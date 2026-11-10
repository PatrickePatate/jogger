<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Data\PoolMatch;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolDemand;
use App\Domain\Runners\Actions\ProvisionRunner;
use App\Support\Exceptions\GithubRateLimited;
use App\Support\Exceptions\HostUnavailable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Provisions one runner for a pool, optionally on behalf of a queued job. Waiting conditions (no host, pool full,
 * GitHub rate limit) put the job back on the queue; they are recorded on the workflow job so the UI can say why
 * it is still waiting.
 */
final class ProvisionRunnerJob implements ShouldQueue
{
    use Queueable;

    /** Real failures (GitHub errors) before giving up; waiting does not count. */
    public int $maxExceptions = 3;

    public function __construct(public readonly string $poolId, public readonly ?string $jobId = null) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(ProvisionRunner $provision, PoolDemand $demand): void
    {
        $pool = RunnerPool::query()->with('installation')->find($this->poolId);
        $job = $this->jobId !== null ? WorkflowJob::query()->find($this->jobId) : null;

        // The job was picked up, cancelled or deleted while this waited.
        if ($pool === null || ($this->jobId !== null && ($job === null || $job->status !== JobStatus::Queued))) {
            return;
        }

        // Someone else (another job's runner, the warm pool) already covered the need while this waited.
        if ($demand->deficit($pool) <= 0) {
            $job?->forceFill(['unmatched_reason' => null])->save();

            return;
        }

        try {
            $runner = $provision($pool);
        } catch (HostUnavailable) {
            $this->waiting($job, PoolMatch::describe(PoolMatch::NO_CAPACITY, 'no host has room for a runner of this pool'), 30);

            return;
        } catch (GithubRateLimited $e) {
            $this->waiting($job, PoolMatch::describe('GITHUB_RATE_LIMITED', 'waiting for the GitHub API rate limit to reset'), max(5, $e->retryAfterSeconds ?? 60));

            return;
        }

        if ($runner === null) {
            if ($job !== null) {
                $reason = $pool->isPaused() ? PoolMatch::describe(PoolMatch::POOL_PAUSED, 'the pool is paused') : PoolMatch::describe(PoolMatch::NO_CAPACITY, 'the pool is at its maximum number of runners');
                $this->waiting($job, $reason, 20);
            }

            return;
        }

        $job?->forceFill(['unmatched_reason' => null])->save();
    }

    private function waiting(?WorkflowJob $job, string $reason, int $delay): void
    {
        $job?->forceFill(['unmatched_reason' => $reason])->save();
        $this->release($delay);
    }
}
