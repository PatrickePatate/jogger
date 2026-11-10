<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Actions\MatchPool;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolDemand;
use App\Jobs\ProvisionRunnerJob;

/**
 * Decides what a queued job needs: which pool serves it and whether that pool must start another runner.
 * Runners are not reserved for a job (GitHub hands a job to any matching runner), so this only keeps the number
 * of waiting runners in step with the number of waiting jobs.
 */
final class ScheduleJob
{
    public function __construct(private readonly MatchPool $match, private readonly PoolDemand $demand) {}

    /**
     * @return RunnerPool|null the pool the job was assigned to
     */
    public function __invoke(WorkflowJob $job): ?RunnerPool
    {
        if ($job->status !== JobStatus::Queued) {
            return null;
        }

        $result = ($this->match)($job->repository, $job->labels);

        if ($result->pool === null) {
            // GitHub-hosted jobs (ubuntu-latest, …) are not ours to explain.
            $job->forceFill([
                'pool_id' => null,
                'unmatched_reason' => MatchPool::wantsSelfHosted($job->labels) ? $result->reason() : null,
            ])->save();

            return null;
        }

        $pool = $result->pool;
        $job->forceFill(['pool_id' => $pool->id, 'unmatched_reason' => null])->save();

        if ($this->demand->deficit($pool) > 0) {
            ProvisionRunnerJob::dispatch($pool->id, $job->id);
        }

        return $pool;
    }
}
