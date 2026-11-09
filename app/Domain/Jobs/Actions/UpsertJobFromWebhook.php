<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Github\Data\WorkflowJobData;
use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Events\JobStatusChanged;
use App\Domain\Jobs\Models\WorkflowJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/** Mirrors one `workflow_job` webhook into workflow_jobs. Safe to run twice and in any order. */
final class UpsertJobFromWebhook
{
    public function __construct(private readonly TransitionJob $transition) {}

    /**
     * @return WorkflowJob|null null when the repository is unknown to Jogger
     */
    public function __invoke(WorkflowJobData $data): ?WorkflowJob
    {
        $repository = Repository::query()->where('github_repo_id', $data->repositoryId)->first();

        if ($repository === null) {
            Log::info('Ignoring workflow_job for a repository Jogger does not know.', [
                'github_job_id' => $data->jobId,
                'repository' => $data->repositoryFullName,
            ]);

            return null;
        }

        $status = JobStatus::fromGithub($data->status);
        $conclusion = JobConclusion::fromGithub($data->conclusion);

        $job = WorkflowJob::query()->where('github_job_id', $data->jobId)->first();

        if ($job === null) {
            $job = $this->create($repository, $data, $status, $conclusion);

            if ($job->wasRecentlyCreated) {
                event(new JobStatusChanged($job));

                return $job;
            }
        }

        // Descriptive fields are always safe to refresh; the status only ever moves forward.
        $job->forceFill([
            'workflow_name' => $data->workflowName ?? $job->workflow_name,
            'job_name' => $data->name,
            'labels' => $data->labels,
            'head_branch' => $data->headBranch ?? $job->head_branch,
            'started_at' => $job->started_at ?? ($status->rank() >= JobStatus::InProgress->rank() ? $data->startedAt : null),
        ])->save();

        if ($job->status->canAdvanceTo($status)) {
            ($this->transition)($job, $status, $conclusion, $data->startedAt, $data->completedAt);
        }

        return $job->refresh();
    }

    private function create(Repository $repository, WorkflowJobData $data, JobStatus $status, ?JobConclusion $conclusion): WorkflowJob
    {
        $started = $status->rank() >= JobStatus::InProgress->rank();
        $completed = $status === JobStatus::Completed;

        try {
            return WorkflowJob::query()->create([
                'github_job_id' => $data->jobId,
                'github_run_id' => $data->runId,
                'run_attempt' => $data->runAttempt,
                'repository_id' => $repository->id,
                'workflow_name' => $data->workflowName,
                'job_name' => $data->name,
                'status' => $status,
                'conclusion' => $completed ? ($conclusion ?? JobConclusion::Neutral) : null,
                'labels' => $data->labels,
                'head_sha' => $data->headSha,
                'head_branch' => $data->headBranch,
                'actor' => $data->actor,
                'html_url' => $data->htmlUrl,
                'queued_at' => $data->createdAt ?? now(),
                'started_at' => $started ? ($data->startedAt ?? $data->completedAt ?? now()) : null,
                'completed_at' => $completed ? ($data->completedAt ?? now()) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery created it first; fall through to the update path.
            return WorkflowJob::query()->where('github_job_id', $data->jobId)->firstOrFail();
        }
    }
}
