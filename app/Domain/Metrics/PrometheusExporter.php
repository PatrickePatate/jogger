<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Github\Gateway\RateLimitTracker;
use App\Domain\Github\Models\Installation;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Runners\Enums\RunnerState;
use Illuminate\Support\Facades\DB;

/** Renders the instance's current state in the Prometheus text exposition format (version 0.0.4). */
final class PrometheusExporter
{
    /** Upper bounds, in seconds, of the queue-wait histogram. */
    public const array WAIT_BUCKETS = [1, 5, 15, 30, 60, 120, 300, 600, 1800];

    public function __construct(private readonly RateLimitTracker $rateLimits) {}

    public function render(): string
    {
        $out = [];

        $runners = DB::table('runners')->whereIn('state', RunnerState::values(RunnerState::present()))->groupBy('state')->selectRaw('state, count(*) as n')->pluck('n', 'state');
        $this->metric($out, 'jogger_runners', 'gauge', 'Runners that currently have a container, by state.',
            array_map(fn (RunnerState $s) => [['state' => $s->value], (int) ($runners[$s->value] ?? 0)], RunnerState::present()));

        $jobs = DB::table('workflow_jobs')->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');
        $this->metric($out, 'jogger_jobs', 'gauge', 'Jobs known to Jogger, by status.',
            array_map(fn (JobStatus $s) => [['status' => $s->value], (int) ($jobs[$s->value] ?? 0)], JobStatus::cases()));
        $this->metric($out, 'jogger_queue_depth', 'gauge', 'Jobs waiting for a runner.',
            [[[], (int) ($jobs[JobStatus::Queued->value] ?? 0) + (int) ($jobs[JobStatus::Waiting->value] ?? 0)]]);

        $this->waitHistogram($out);

        $hosts = Host::query()->where('status', '!=', HostStatus::Revoked->value)->orderBy('name')->get();
        $this->metric($out, 'jogger_host_up', 'gauge', '1 when the host is online, else 0.',
            $hosts->map(fn (Host $h) => [['host' => $h->name, 'status' => $h->status->value], $h->status === HostStatus::Online ? 1 : 0])->all());
        foreach ([
            ['jogger_host_cpu_cores', 'CPU cores of the host.', 'cpu_total', 1],
            ['jogger_host_cpu_used_cores', 'CPU cores in use on the host.', 'cpu_used', 1],
            ['jogger_host_memory_bytes', 'Memory of the host.', 'memory_total_mb', 1048576],
            ['jogger_host_memory_used_bytes', 'Memory in use on the host.', 'memory_used_mb', 1048576],
            ['jogger_host_disk_free_bytes', 'Free disk space on the host.', 'disk_free_gb', 1073741824],
        ] as [$name, $help, $column, $factor]) {
            $this->metric($out, $name, 'gauge', $help, $hosts->filter(fn (Host $h) => $h->getAttribute($column) !== null)
                ->map(fn (Host $h) => [['host' => $h->name], (float) $h->getAttribute($column) * $factor])->values()->all());
        }

        $limits = [];
        foreach (Installation::query()->orderBy('account_login')->get() as $installation) {
            $state = $this->rateLimits->state((int) $installation->github_installation_id);
            if ($state !== null) {
                $limits[] = [['installation' => (string) $installation->account_login], $state['remaining']];
            }
        }
        $this->metric($out, 'jogger_github_rate_limit_remaining', 'gauge', 'GitHub API requests left in the current window, per installation.', $limits);

        return implode("\n", $out)."\n";
    }

    /**
     * Queue wait of the jobs started in the last hour, as a cumulative histogram.
     *
     * @param  list<string>  $out
     */
    private function waitHistogram(array &$out): void
    {
        $select = [];
        foreach (self::WAIT_BUCKETS as $le) {
            $select[] = "count(*) filter (where extract(epoch from (started_at - queued_at)) <= {$le}) as le_{$le}";
        }
        $row = DB::table('workflow_jobs')->where('started_at', '>=', now()->subHour())->whereNotNull('queued_at')
            ->selectRaw(implode(', ', $select).', count(*) as total, coalesce(sum(extract(epoch from (started_at - queued_at))), 0) as sum')->first();

        $name = 'jogger_queue_wait_seconds';
        $out[] = "# HELP {$name} Seconds jobs started in the last hour waited for a runner.";
        $out[] = "# TYPE {$name} histogram";
        foreach (self::WAIT_BUCKETS as $le) {
            $out[] = "{$name}_bucket{le=\"{$le}\"} ".(int) ($row->{"le_{$le}"} ?? 0);
        }
        $out[] = "{$name}_bucket{le=\"+Inf\"} ".(int) ($row->total ?? 0);
        $out[] = "{$name}_sum ".self::number((float) ($row->sum ?? 0));
        $out[] = "{$name}_count ".(int) ($row->total ?? 0);
    }

    /**
     * @param  list<string>  $out
     * @param  array<int, array{array<string, string>, int|float}>  $samples
     */
    private function metric(array &$out, string $name, string $type, string $help, array $samples): void
    {
        $out[] = "# HELP {$name} {$help}";
        $out[] = "# TYPE {$name} {$type}";
        foreach ($samples as [$labels, $value]) {
            $pairs = [];
            foreach ($labels as $key => $label) {
                $pairs[] = $key.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $label).'"';
            }
            $out[] = $name.($pairs === [] ? '' : '{'.implode(',', $pairs).'}').' '.self::number($value);
        }
    }

    private static function number(int|float $value): string
    {
        return is_int($value) || floor($value) === $value ? (string) (int) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
