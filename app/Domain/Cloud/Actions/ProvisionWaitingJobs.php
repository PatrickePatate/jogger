<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Actions\ReconcilePoolCapacity;
use App\Domain\Pools\Enums\PoolStatus;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolDemand;
use App\Jobs\ProvisionRunnerJob;

/**
 * Queues runner provisioning for the jobs waiting on the pools of a template, after one of its hosts came online.
 * Each pool gets at most as many provisioning jobs as its deficit, bounded per pass like ReconcilePoolCapacity.
 */
final class ProvisionWaitingJobs
{
    public function __construct(private readonly PoolDemand $demand) {}

    /** @return int provisioning jobs dispatched */
    public function __invoke(HostTemplate $template): int
    {
        $dispatched = 0;

        $template->pools()->where('runner_pools.status', PoolStatus::Active->value)->get()
            ->each(function (RunnerPool $pool) use (&$dispatched) {
                $wanted = min(ReconcilePoolCapacity::MAX_PER_PASS, max(0, $this->demand->deficit($pool)));
                if ($wanted === 0) {
                    return;
                }

                WorkflowJob::query()
                    ->where('pool_id', $pool->id)
                    ->where('status', JobStatus::Queued->value)
                    ->whereNull('runner_id')
                    ->orderBy('queued_at')
                    ->limit($wanted)
                    ->pluck('id')
                    ->each(function (string $jobId) use ($pool, &$dispatched) {
                        ProvisionRunnerJob::dispatch($pool->id, $jobId);
                        $dispatched++;
                    });
            });

        return $dispatched;
    }
}
