<?php

declare(strict_types=1);

namespace App\Domain\Github\Handlers;

use App\Domain\Github\Contracts\WebhookHandler;
use App\Domain\Github\Data\WorkflowJobData;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Jobs\Actions\AssignJobToRunner;
use App\Domain\Jobs\Actions\ScheduleJob;
use App\Domain\Jobs\Actions\UpsertJobFromWebhook;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Runners\Actions\TerminateRunner;
use App\Domain\Runners\Models\Runner;
use App\Jobs\TrimIdleRunnersJob;

/**
 * Mirrors `workflow_job` events into Jogger's job table, then acts on what the job now needs:
 * queued → find its pool and make sure a runner is coming; in progress → link the runner that took it;
 * completed → tear that runner down (or, if none ever took it, trim the runner started for it).
 * Every step tolerates redelivery and out-of-order events.
 */
final class HandleWorkflowJob implements WebhookHandler
{
    public function __construct(
        private readonly UpsertJobFromWebhook $upsert,
        private readonly ScheduleJob $schedule,
        private readonly AssignJobToRunner $assign,
        private readonly TerminateRunner $terminate,
    ) {}

    public function handle(WebhookDelivery $delivery): void
    {
        $data = WorkflowJobData::from($delivery->payload);
        $job = ($this->upsert)($data);
        if ($job === null) {
            return;
        }

        match ($job->status) {
            JobStatus::Queued => ($this->schedule)($job),
            JobStatus::InProgress => ($this->assign)($job, $data->runnerName, $data->runnerId),
            JobStatus::Completed => $this->completed($job, $data),
            default => null,
        };
    }

    private function completed(WorkflowJob $job, WorkflowJobData $data): void
    {
        // A job can complete before (or without) its in_progress event reaching us.
        if ($job->runner_id === null && $data->runnerName !== null) {
            ($this->assign)($job, $data->runnerName, $data->runnerId);
            $job->refresh();
        }

        $runner = $job->runner_id !== null ? Runner::query()->find($job->runner_id) : null;
        if ($runner !== null) {
            ($this->terminate)($runner, 'completed');

            return;
        }

        // Never picked up (cancelled or skipped while queued): the runner started for it has nobody to wait for.
        if ($job->pool_id !== null) {
            TrimIdleRunnersJob::dispatch($job->pool_id)->delay(TrimIdleRunnersJob::DELAY_SECONDS);
        }
    }
}
