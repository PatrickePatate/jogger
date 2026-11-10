<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use Illuminate\Support\Facades\DB;

/**
 * Chooses the host for a new runner of a pool: online (so not cordoned, draining, offline or revoked), carrying
 * the pool's required host labels, of the architecture the pool's labels name, and with room for the runner.
 * Among those, the one with the most free memory. Null when no host qualifies.
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

        $best = null;
        $bestFree = -1.0;

        $hosts = Host::query()->where('status', HostStatus::Online->value)->whereKeyNot($except)->orderBy('name')->get();
        foreach ($hosts as $host) {
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

            if ($freeMemory > $bestFree) {
                $best = $host;
                $bestFree = $freeMemory;
            }
        }

        return $best;
    }
}
