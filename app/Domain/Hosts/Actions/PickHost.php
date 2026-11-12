<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostImage;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use Illuminate\Support\Facades\DB;

/**
 * Chooses the host for a new runner of a pool: online (so not cordoned, draining, offline or revoked), carrying
 * the pool's required host labels, of the architecture the pool's labels name, and with room for the runner.
 * Among those, hosts that already have the pool's image come first, then the one with the most free memory.
 * A cloud host (one with a host template) also needs its cloud state to be online and fewer present runners than
 * its template's max_runners_per_host. Null when no host qualifies.
 */
final class PickHost
{
    /** What a runner is assumed to need when its pool sets no limit. */
    public const DEFAULT_CPU = 1.0;

    public const DEFAULT_MEMORY_MB = 1024;

    private const ARCHES = ['x64', 'arm64', 'arm'];

    /**
     * @param  list<string>  $except  host ids to skip (already tried)
     */
    public function __invoke(RunnerPool $pool, array $except = []): ?Host
    {
        $needCpu = $pool->cpu_limit ?? self::DEFAULT_CPU;
        $needMemory = $pool->memory_limit_mb ?? self::DEFAULT_MEMORY_MB;
        $arches = array_values(array_intersect(self::ARCHES, array_map('mb_strtolower', $pool->labels)));
        $required = array_map('mb_strtolower', $pool->allowed_host_labels);

        // What Jogger already promised to runners on each host. More reliable than momentary usage: a runner
        // that just started has not consumed its share yet.
        $reserved = DB::table('runners')
            ->join('runner_pools', 'runner_pools.id', '=', 'runners.pool_id')
            ->whereIn('runners.state', RunnerState::values(RunnerState::present()))
            ->whereNotNull('runners.host_id')
            ->groupBy('runners.host_id')
            ->selectRaw('runners.host_id, sum(coalesce(runner_pools.cpu_limit, ?)) as cpu, sum(coalesce(runner_pools.memory_limit_mb, ?)) as memory', [self::DEFAULT_CPU, self::DEFAULT_MEMORY_MB])
            ->get()->keyBy('host_id');

        // Hosts that already hold the image start a runner in seconds; the others must download it first.
        $warm = HostImage::query()->where('image', $pool->image)->where('status', HostImage::READY)->pluck('host_id')->flip();

        $best = null;
        $bestScore = [-1, -1.0];

        $hosts = Host::query()->where('status', HostStatus::Online->value)->whereKeyNot($except)->orderBy('name')->get();

        $cloud = $hosts->filter(fn (Host $host) => $host->host_template_id !== null);
        $cloudLimits = $cloud->isEmpty() ? collect() : HostTemplate::query()
            ->whereIn('id', $cloud->pluck('host_template_id')->unique()->all())
            ->pluck('max_runners_per_host', 'id');
        $present = $cloud->isEmpty() ? collect() : DB::table('runners')
            ->whereIn('host_id', $cloud->pluck('id')->all())
            ->whereIn('state', RunnerState::values(RunnerState::present()))
            ->groupBy('host_id')
            ->selectRaw('host_id, count(*) as n')
            ->pluck('n', 'host_id');

        foreach ($hosts as $host) {
            if ($host->host_template_id !== null) {
                if ($host->cloud_state !== CloudHostState::Online) {
                    continue;
                }
                if ((int) ($present[$host->id] ?? 0) >= (int) ($cloudLimits[$host->host_template_id] ?? 0)) {
                    continue;
                }
            }
            if ($arches !== [] && ! in_array(mb_strtolower((string) $host->arch), $arches, true)) {
                continue;
            }
            if (array_diff($required, array_map('mb_strtolower', $host->labels)) !== []) {
                continue;
            }

            $freeCpu = (float) $host->cpu_total - max((float) $host->cpu_used, (float) ($reserved[$host->id]->cpu ?? 0));
            $freeMemory = (float) $host->memory_total_mb - max((float) $host->memory_used_mb, (float) ($reserved[$host->id]->memory ?? 0));
            if ($freeCpu < $needCpu || $freeMemory < $needMemory) {
                continue;
            }

            $score = [$warm->has($host->id) ? 1 : 0, $freeMemory];
            if ($score > $bestScore) {
                $best = $host;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
