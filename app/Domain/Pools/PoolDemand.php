<?php

declare(strict_types=1);

namespace App\Domain\Pools;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;

/**
 * How many runners a pool is short of (positive) or has too many of (negative):
 * warm minimum + jobs waiting for this pool − runners waiting for a job (starting or idle).
 * Runners are not reserved for jobs, so every scaling decision is this one subtraction.
 */
final class PoolDemand
{
    public function deficit(RunnerPool $pool): int
    {
        $jobs = WorkflowJob::query()->where('pool_id', $pool->id)->where('status', JobStatus::Queued->value)->count();
        $runners = Runner::query()->where('pool_id', $pool->id)->inState(RunnerState::Provisioning, RunnerState::Idle)->count();

        return $pool->min_idle + $jobs - $runners;
    }
}
