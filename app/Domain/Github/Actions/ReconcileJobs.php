<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Actions\ScheduleJob;
use App\Domain\Jobs\Actions\UpsertJobFromWebhook;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Enums\PoolStatus;
use App\Domain\Pools\Models\RunnerPool;
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

    public function __construct(
        private readonly GithubGateway $github,
        private readonly UpsertJobFromWebhook $upsert,
        private readonly ScheduleJob $schedule,
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
            if (! $repository->installation->isActive()) {
                continue;
            }

            foreach ($this->github->listQueuedJobs($repository->installation, $repository) as $data) {
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

        return $imported;
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
