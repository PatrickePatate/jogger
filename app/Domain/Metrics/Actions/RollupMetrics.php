<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Actions;

use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Metrics\Models\MetricSample;
use App\Domain\Runners\Enums\RunnerState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Summarises one minute or one hour of jobs into `metric_samples`, for the instance, each pool and each repository.
 * Recomputing a bucket overwrites it, so running it twice (or backfilling) is harmless.
 */
final class RollupMetrics
{
    /** @return int samples written */
    public function __invoke(CarbonInterface $at, string $resolution): int
    {
        $from = $resolution === MetricSample::HOUR ? CarbonImmutable::instance($at)->startOfHour() : CarbonImmutable::instance($at)->startOfMinute();
        $to = $resolution === MetricSample::HOUR ? $from->addHour() : $from->addMinute();

        $rows = [];
        foreach ([[MetricSample::GLOBAL, null], [MetricSample::POOL, 'pool_id'], [MetricSample::REPO, 'repository_id']] as [$type, $column]) {
            foreach ($this->flow($column, $from, $to) as $row) {
                $id = $column === null ? MetricSample::INSTANCE : (string) $row->subject;
                $rows["{$type}:{$id}"] = [
                    'bucket' => $from, 'resolution' => $resolution, 'subject_type' => $type, 'subject_id' => $id,
                    'queue_depth' => (int) $row->queue_depth,
                    'runners_busy' => null, 'runners_idle' => null,
                    'queue_wait_p50_ms' => $row->p50 === null ? null : (int) round((float) $row->p50),
                    'queue_wait_p95_ms' => $row->p95 === null ? null : (int) round((float) $row->p95),
                    'jobs_started' => (int) $row->jobs_started,
                    'jobs_succeeded' => (int) $row->jobs_succeeded,
                    'jobs_failed' => (int) $row->jobs_failed,
                    'busy_seconds' => (int) round((float) $row->busy_seconds),
                ];
            }
        }

        foreach ($this->runners($resolution, $from, $to) as $key => [$busy, $idle]) {
            [$type, $id] = explode(':', $key, 2);
            $rows[$key] ??= [
                'bucket' => $from, 'resolution' => $resolution, 'subject_type' => $type, 'subject_id' => $id,
                'queue_depth' => 0, 'queue_wait_p50_ms' => null, 'queue_wait_p95_ms' => null,
                'jobs_started' => 0, 'jobs_succeeded' => 0, 'jobs_failed' => 0, 'busy_seconds' => 0,
            ];
            $rows[$key]['runners_busy'] = $busy;
            $rows[$key]['runners_idle'] = $idle;
        }

        // The instance always gets a sample, so charts have no holes in quiet periods.
        $rows[MetricSample::GLOBAL.':'.MetricSample::INSTANCE] ??= [
            'bucket' => $from, 'resolution' => $resolution, 'subject_type' => MetricSample::GLOBAL, 'subject_id' => MetricSample::INSTANCE,
            'queue_depth' => 0, 'runners_busy' => null, 'runners_idle' => null, 'queue_wait_p50_ms' => null, 'queue_wait_p95_ms' => null,
            'jobs_started' => 0, 'jobs_succeeded' => 0, 'jobs_failed' => 0, 'busy_seconds' => 0,
        ];

        MetricSample::query()->upsert(array_values($rows), ['resolution', 'subject_type', 'subject_id', 'bucket'], [
            'queue_depth', 'runners_busy', 'runners_idle', 'queue_wait_p50_ms', 'queue_wait_p95_ms', 'jobs_started', 'jobs_succeeded', 'jobs_failed', 'busy_seconds',
        ]);

        return count($rows);
    }

    /** Drops minute samples after two days and hour samples after 400. */
    public function prune(): int
    {
        return MetricSample::query()->where('resolution', MetricSample::MINUTE)->where('bucket', '<', now()->subDays(2))->delete()
            + MetricSample::query()->where('resolution', MetricSample::HOUR)->where('bucket', '<', now()->subDays(400))->delete();
    }

    /**
     * Jobs that started or finished in [from, to), and the queue depth at `to`.
     *
     * @return iterable<object>
     */
    private function flow(?string $column, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        // Timestamps come from Carbon, never from input, so they are inlined: each appears many times.
        $a = "'".$from->format('Y-m-d H:i:s')."'";
        $b = "'".$to->format('Y-m-d H:i:s')."'";
        $started = "started_at >= {$a} and started_at < {$b}";
        $completed = "completed_at >= {$a} and completed_at < {$b}";
        $waiting = "queued_at < {$b} and (started_at is null or started_at >= {$b}) and (completed_at is null or completed_at >= {$b})";
        $wait = 'extract(epoch from (started_at - queued_at)) * 1000';
        $failed = "'".JobConclusion::Failure->value."','".JobConclusion::TimedOut->value."'";

        return DB::table('workflow_jobs')
            ->when($column !== null, fn ($q) => $q->whereNotNull($column)->groupBy($column)->selectRaw("{$column} as subject"))
            ->whereRaw("(({$started}) or ({$completed}) or ({$waiting}))")
            ->selectRaw("count(*) filter (where {$started}) as jobs_started")
            ->selectRaw("count(*) filter (where {$completed} and conclusion = '".JobConclusion::Success->value."') as jobs_succeeded")
            ->selectRaw("count(*) filter (where {$completed} and conclusion in ({$failed})) as jobs_failed")
            ->selectRaw("coalesce(sum(extract(epoch from (completed_at - started_at))) filter (where {$completed} and started_at is not null), 0) as busy_seconds")
            ->selectRaw("percentile_cont(0.5) within group (order by {$wait}) filter (where {$started} and queued_at is not null) as p50")
            ->selectRaw("percentile_cont(0.95) within group (order by {$wait}) filter (where {$started} and queued_at is not null) as p95")
            ->selectRaw("count(*) filter (where {$waiting}) as queue_depth")
            ->get();
    }

    /**
     * Busy and idle runners per subject key. A minute is a snapshot of now (the past cannot be reconstructed, so
     * old minutes get none); an hour is the average of its minutes.
     *
     * @return array<string, array{int, int}>
     */
    private function runners(string $resolution, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        if ($resolution === MetricSample::HOUR) {
            $rows = MetricSample::query()->where('resolution', MetricSample::MINUTE)->whereNotNull('runners_busy')
                ->where('bucket', '>=', $from)->where('bucket', '<', $to)->whereIn('subject_type', [MetricSample::GLOBAL, MetricSample::POOL])
                ->groupBy('subject_type', 'subject_id')->toBase()
                ->selectRaw('subject_type, subject_id, round(avg(runners_busy)) as busy, round(avg(runners_idle)) as idle')->get();
            foreach ($rows as $row) {
                $out["{$row->subject_type}:{$row->subject_id}"] = [(int) $row->busy, (int) $row->idle];
            }

            return $out;
        }
        if ($to->lt(now()->subMinutes(2))) {
            return [];
        }

        $total = [0, 0];
        $rows = DB::table('runners')->whereIn('state', [RunnerState::Busy->value, RunnerState::Idle->value])
            ->groupBy('pool_id')->selectRaw('pool_id')
            ->selectRaw('count(*) filter (where state = ?) as busy', [RunnerState::Busy->value])
            ->selectRaw('count(*) filter (where state = ?) as idle', [RunnerState::Idle->value])->get();
        foreach ($rows as $row) {
            $out[MetricSample::POOL.':'.$row->pool_id] = [(int) $row->busy, (int) $row->idle];
            $total = [$total[0] + (int) $row->busy, $total[1] + (int) $row->idle];
        }
        $out[MetricSample::GLOBAL.':'.MetricSample::INSTANCE] = $total;

        return $out;
    }
}
