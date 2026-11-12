<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Enums\PoolStatus;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Support\Facades\DB;

/**
 * Asks for the cloud servers the waiting jobs and the warm minimums need. Per active template with active pools:
 *   demand   = queued jobs of those pools with no runner, less the runners of those pools that are still waiting;
 *   capacity = slots left on the template's hosts that are requested, booting or online;
 *   servers  = max(ceil((demand − capacity) / max_runners_per_host), warm_hosts − open hosts, 0).
 * ProvisionCloudHost enforces the caps and stops the loop when one is reached.
 */
final class ScaleCloudHosts
{
    /** States whose slots count as capacity: they hold, or will hold, runners. */
    private const CAPACITY_STATES = [CloudHostState::Requested, CloudHostState::Booting, CloudHostState::Online];

    public function __construct(private readonly ProvisionCloudHost $provision) {}

    /**
     * @param  HostTemplate|null  $only  restrict the pass to one template
     * @return int servers requested
     */
    public function __invoke(?HostTemplate $only = null): int
    {
        $templates = HostTemplate::query()
            ->where('status', 'active')
            ->whereHas('pools', fn ($query) => $query->where('runner_pools.status', PoolStatus::Active->value))
            ->when($only !== null, fn ($query) => $query->whereKey($only?->getKey()))
            ->orderBy('name')
            ->get();

        $requested = 0;
        foreach ($templates as $template) {
            $needed = $this->needed($template);

            for ($i = 0; $i < $needed; $i++) {
                if (($this->provision)($template) === null) {
                    break;
                }
                $requested++;
            }
        }

        return $requested;
    }

    /** Servers this template should gain right now (not yet capped). */
    private function needed(HostTemplate $template): int
    {
        $perHost = max(1, (int) $template->max_runners_per_host);

        $poolIds = $template->pools()->where('runner_pools.status', PoolStatus::Active->value)->pluck('runner_pools.id')->all();
        $demand = 0;
        if ($poolIds !== []) {
            $queued = WorkflowJob::query()
                ->where('status', JobStatus::Queued->value)
                ->whereNull('runner_id')
                ->whereIn('pool_id', $poolIds)
                ->count();
            // A runner that is already starting or idle will take one of those jobs; it needs no new server.
            $waiting = Runner::query()->whereIn('pool_id', $poolIds)->inState(RunnerState::Provisioning, RunnerState::Idle)->count();
            $demand = max(0, $queued - $waiting);
        }

        $hosts = Host::query()
            ->where('host_template_id', $template->id)
            ->whereIn('cloud_state', array_map(fn (CloudHostState $state) => $state->value, self::CAPACITY_STATES))
            ->get(['id']);

        $present = $hosts->isEmpty() ? collect() : DB::table('runners')
            ->whereIn('host_id', $hosts->pluck('id')->all())
            ->whereIn('state', RunnerState::values(RunnerState::present()))
            ->groupBy('host_id')
            ->selectRaw('host_id, count(*) as n')
            ->pluck('n', 'host_id');

        $capacity = 0;
        foreach ($hosts as $host) {
            $capacity += max(0, $perHost - (int) ($present[$host->id] ?? 0));
        }

        $open = Host::query()->where('host_template_id', $template->id)->whereIn('cloud_state', CloudHostState::open())->count();

        return max(
            (int) ceil(max(0, $demand - $capacity) / $perHost),
            max(0, (int) $template->warm_hosts - $open),
        );
    }
}
