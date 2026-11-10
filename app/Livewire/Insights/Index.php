<?php

declare(strict_types=1);

namespace App\Livewire\Insights;

use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Metrics\Models\MetricSample;
use App\Domain\Pools\Models\RunnerPool;
use App\Models\User;
use App\Support\Ui\Duration;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Trends from the metric rollups, limited to what the user may see. */
#[Layout('components.layouts.app')]
#[Title('Insights')]
final class Index extends Component
{
    /** range => [label, resolution, minutes] */
    public const array RANGES = [
        '1h' => ['Last hour', MetricSample::MINUTE, 60],
        '24h' => ['Last 24 hours', MetricSample::HOUR, 1440],
        '7d' => ['Last 7 days', MetricSample::HOUR, 10080],
        '30d' => ['Last 30 days', MetricSample::HOUR, 43200],
    ];

    #[Url(except: '24h')]
    public string $range = '24h';

    public function render(): View
    {
        $user = $this->user();
        $series = $this->series($user);
        $started = (int) $series->sum('jobs_started');
        $succeeded = (int) $series->sum('jobs_succeeded');
        $failed = (int) $series->sum('jobs_failed');
        $p95 = $series->max('p95');

        return view('livewire.insights.index', [
            'ranges' => self::RANGES,
            'series' => $series,
            'labels' => [$series->first()?->bucket, $series->last()?->bucket],
            'totals' => [
                'started' => $started,
                'finished' => $succeeded + $failed,
                'success_rate' => $succeeded + $failed > 0 ? (int) round($succeeded / ($succeeded + $failed) * 100) : null,
                'p50' => $started > 0 ? self::ms((int) round($series->sum(fn ($r) => (float) $r->p50 * (int) $r->jobs_started) / $started)) : '—',
                'p95' => $p95 !== null ? self::ms((int) $p95) : '—',
                'minutes' => (int) round($series->sum('busy_seconds') / 60),
            ],
            'days' => $this->days($series),
            'repositories' => $this->repositories($user),
            'pools' => $this->pools($user),
            'hosts' => $user->isAdmin() ? Host::query()->where('status', '!=', HostStatus::Revoked->value)->orderBy('name')->get() : collect(),
        ]);
    }

    public function export(): StreamedResponse
    {
        $rows = $this->repositories($this->user());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['repository', 'jobs_started', 'jobs_succeeded', 'jobs_failed', 'success_rate_percent', 'minutes']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['name'], $row['started'], $row['succeeded'], $row['failed'], $row['success_rate'] ?? '', $row['minutes']]);
            }
            fclose($out);
        }, "jogger-insights-{$this->range}.csv", ['Content-Type' => 'text/csv']);
    }

    public static function ms(int $ms): string
    {
        return $ms < 1000 ? "{$ms} ms" : Duration::seconds((int) round($ms / 1000));
    }

    /**
     * One row per bucket. Administrators read the instance samples; others the sum of the repositories and pools
     * they may see (their p50 is then a job-weighted average and their p95 the worst bucket, both approximations).
     *
     * @return Collection<int, \stdClass>
     */
    private function series(User $user): Collection
    {
        [, $resolution] = $this->window();

        $flow = $this->samples($resolution)
            ->when($user->isAdmin(),
                fn (Builder $q) => $q->where('subject_type', MetricSample::GLOBAL),
                fn (Builder $q) => $q->where('subject_type', MetricSample::REPO)->whereIn('subject_id', Repository::query()->visibleTo($user)->select('repositories.id')))
            ->groupBy('bucket')->orderBy('bucket')->toBase()
            ->selectRaw('bucket, sum(queue_depth) as queue_depth, sum(jobs_started) as jobs_started, sum(jobs_succeeded) as jobs_succeeded, sum(jobs_failed) as jobs_failed, sum(busy_seconds) as busy_seconds')
            ->selectRaw('max(queue_wait_p95_ms) as p95')
            ->selectRaw('sum(queue_wait_p50_ms::bigint * jobs_started) / nullif(sum(jobs_started) filter (where queue_wait_p50_ms is not null), 0) as p50')
            ->get();

        $runners = $this->samples($resolution)->whereNotNull('runners_busy')
            ->when($user->isAdmin(),
                fn (Builder $q) => $q->where('subject_type', MetricSample::GLOBAL),
                fn (Builder $q) => $q->where('subject_type', MetricSample::POOL)->whereIn('subject_id', RunnerPool::query()->visibleTo($user)->select('runner_pools.id')))
            ->groupBy('bucket')->toBase()->selectRaw('bucket, sum(runners_busy) as busy, sum(runners_idle) as idle')->get()->keyBy('bucket');

        return $flow->map(function (\stdClass $row) use ($runners) {
            $row->busy = isset($runners[$row->bucket]) ? (int) $runners[$row->bucket]->busy : null;
            $row->idle = isset($runners[$row->bucket]) ? (int) $runners[$row->bucket]->idle : null;

            return $row;
        });
    }

    /**
     * Jobs per day with their success rate.
     *
     * @param  Collection<int, \stdClass>  $series
     * @return list<array{day: string, finished: int, success_rate: int|null}>
     */
    private function days(Collection $series): array
    {
        return $series->groupBy(fn (\stdClass $r) => substr((string) $r->bucket, 0, 10))
            ->map(function (Collection $rows, string $day): array {
                $ok = (int) $rows->sum('jobs_succeeded');
                $finished = $ok + (int) $rows->sum('jobs_failed');

                return ['day' => $day, 'finished' => $finished, 'success_rate' => $finished > 0 ? (int) round($ok / $finished * 100) : null];
            })->values()->all();
    }

    /**
     * @return list<array{name: string, started: int, succeeded: int, failed: int, success_rate: int|null, minutes: int}>
     */
    private function repositories(User $user): array
    {
        $names = Repository::query()->visibleTo($user)->pluck('full_name', 'id');

        return $this->bySubject(MetricSample::REPO, $names);
    }

    /**
     * @return list<array{name: string, started: int, succeeded: int, failed: int, success_rate: int|null, minutes: int}>
     */
    private function pools(User $user): array
    {
        return $this->bySubject(MetricSample::POOL, RunnerPool::query()->visibleTo($user)->pluck('name', 'id'));
    }

    /**
     * @param  Collection<array-key, mixed>  $names  id => display name; also the visibility filter
     * @return list<array{name: string, started: int, succeeded: int, failed: int, success_rate: int|null, minutes: int}>
     */
    private function bySubject(string $type, Collection $names): array
    {
        [, $resolution] = $this->window();

        return $this->samples($resolution)->where('subject_type', $type)->whereIn('subject_id', $names->keys())
            ->groupBy('subject_id')->toBase()
            ->selectRaw('subject_id, sum(jobs_started) as started, sum(jobs_succeeded) as succeeded, sum(jobs_failed) as failed, sum(busy_seconds) as busy')
            ->havingRaw('sum(jobs_started) + sum(jobs_succeeded) + sum(jobs_failed) > 0')
            ->orderByRaw('sum(busy_seconds) desc')->limit(50)->get()
            ->map(function (object $row) use ($names): array {
                $finished = (int) $row->succeeded + (int) $row->failed;

                return [
                    'name' => (string) $names[$row->subject_id],
                    'started' => (int) $row->started,
                    'succeeded' => (int) $row->succeeded,
                    'failed' => (int) $row->failed,
                    'success_rate' => $finished > 0 ? (int) round((int) $row->succeeded / $finished * 100) : null,
                    'minutes' => (int) round((int) $row->busy / 60),
                ];
            })->values()->all();
    }

    /**
     * @return Builder<MetricSample>
     */
    private function samples(string $resolution): Builder
    {
        [$from] = $this->window();

        return MetricSample::query()->where('resolution', $resolution)->where('bucket', '>=', $from);
    }

    /**
     * @return array{Carbon, string}
     */
    private function window(): array
    {
        [, $resolution, $minutes] = self::RANGES[$this->range] ?? self::RANGES['24h'];

        return [now()->subMinutes($minutes), $resolution];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
