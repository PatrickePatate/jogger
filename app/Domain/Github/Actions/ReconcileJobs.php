<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Handlers\HandleWorkflowJob;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Support\GithubLane;
use App\Domain\Jobs\Actions\ScheduleJob;
use App\Domain\Jobs\Actions\TransitionJob;
use App\Domain\Jobs\Actions\UpsertJobFromWebhook;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Enums\PoolStatus;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Actions\TerminateRunner;
use App\Domain\Runners\Models\Runner;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Support\Collection;

/**
 * Catches jobs whose `queued` webhook never arrived: asks GitHub which jobs are waiting and imports and schedules
 * the ones Jogger does not know. Also re-schedules known queued jobs, which is harmless and heals a lost
 * provisioning.
 */
final class ReconcileJobs
{
    /** Each repository costs API calls, so a pass is bounded. */
    public const MAX_REPOSITORIES = 50;

    /** A running job whose status has not changed for this long is checked against GitHub (a missed completion). */
    public const STALE_AFTER_SECONDS = 180;

    public const MAX_STALE_JOBS = 30;

    public function __construct(
        private readonly GithubGateway $github,
        private readonly UpsertJobFromWebhook $upsert,
        private readonly ScheduleJob $schedule,
        private readonly GithubLane $lane,
        private readonly HandleWorkflowJob $jobs,
        private readonly TransitionJob $transition,
        private readonly TerminateRunner $terminate,
    ) {}

    /**
     * @return int jobs imported
     *
     * @throws GithubRateLimited
     */
    public function __invoke(): int
    {
        $imported = 0;

        foreach ($this->repositories() as $repository) {
            if (! $repository->installation->isActive() || $this->lane->pausedFor($repository->installation_id) > 0) {
                continue;
            }

            try {
                $queued = $this->github->listQueuedJobs($repository->installation, $repository);
            } catch (GithubRateLimited $e) {
                $this->lane->pause($repository->installation_id, $e->retryAfterSeconds);

                continue;
            }

            foreach ($queued as $data) {
                $known = WorkflowJob::query()->where('github_job_id', $data->jobId)->exists();
                $job = ($this->upsert)($data);
                if ($job === null) {
                    continue;
                }
                $imported += $known ? 0 : 1;
                if ($job->status === JobStatus::Queued) {
                    ($this->schedule)($job);
                }
            }
        }

        $this->settleStaleJobs();

        return $imported;
    }

    /**
     * Jobs Jogger still believes are queued or running although nothing changed for a while: ask GitHub. A lost
     * `completed` webhook would otherwise keep the job, its runner and the cloud server behind it alive forever.
     * A job GitHub no longer knows is closed as cancelled.
     */
    private function settleStaleJobs(): void
    {
        $stale = WorkflowJob::query()->onJogger()->with('repository.installation')
            ->whereIn('status', [JobStatus::Queued->value, JobStatus::InProgress->value])
            ->where('updated_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->orderBy('updated_at')->limit(self::MAX_STALE_JOBS)->get();

        foreach ($stale as $job) {
            $installation = $job->repository->installation;
            if (! $installation->isActive() || $this->lane->pausedFor($installation->id) > 0) {
                continue;
            }

            try {
                $data = $this->github->getJob($installation, $job->repository, $job->github_job_id);
            } catch (GithubRateLimited $e) {
                $this->lane->pause($installation->id, $e->retryAfterSeconds);

                continue;
            } catch (GithubApiError) {
                continue;
            }

            if ($data !== null) {
                $this->jobs->apply($data);
                $job->touch();

                continue;
            }

            if (($this->transition)($job, JobStatus::Completed, JobConclusion::Cancelled)) {
                $runner = $job->runner_id !== null ? Runner::query()->find($job->runner_id) : null;
                if ($runner !== null) {
                    ($this->terminate)($runner, 'completed');
                }
            }
        }
    }

    /**
     * Repositories worth asking about: those a pool targets directly, and those of pooled organizations that had
     * a job recently (an organization can have thousands of repositories; most never use Jogger).
     *
     * @return Collection<int, Repository>
     */
    private function repositories(): Collection
    {
        $pools = RunnerPool::query()->where('status', PoolStatus::Active->value)->get();
        $direct = $pools->where('scope', ScopeType::Repository)->pluck('scope_target');
        $orgInstallations = $pools->where('scope', ScopeType::Organization)->pluck('installation_id')->unique();

        return Repository::query()->with('installation')
            ->where(fn ($q) => $q
                ->whereIn('full_name', $direct)
                ->orWhere(fn ($q) => $q->whereIn('installation_id', $orgInstallations)
                    ->whereIn('id', WorkflowJob::query()->where('created_at', '>', now()->subDay())->select('repository_id'))))
            ->orderBy('full_name')->limit(self::MAX_REPOSITORIES)->get();
    }
}
