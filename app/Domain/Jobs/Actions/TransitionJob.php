<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Events\JobStatusChanged;
use App\Domain\Jobs\Models\WorkflowJob;
use Carbon\CarbonInterface;

/**
 * Moves a job forward. The UPDATE only matches rows still in an earlier status, so concurrent or late webhooks
 * cannot move a job backwards and the loser of a race is a silent no-op. Broadcasts exactly once per real change.
 */
final class TransitionJob
{
    /**
     * @return bool whether the job actually changed
     */
    public function __invoke(
        WorkflowJob $job,
        JobStatus $to,
        ?JobConclusion $conclusion = null,
        ?CarbonInterface $startedAt = null,
        ?CarbonInterface $completedAt = null,
    ): bool {
        $changes = ['status' => $to->value, 'updated_at' => now()];

        if ($to === JobStatus::Completed) {
            $changes['conclusion'] = ($conclusion ?? JobConclusion::Neutral)->value;
            $changes['completed_at'] = $completedAt ?? now();
        }

        if ($to->rank() >= JobStatus::InProgress->rank()) {
            // A job completed before we saw it start still gets a start time (best effort).
            $changes['started_at'] = $startedAt ?? $job->started_at ?? $completedAt ?? now();
        }

        $affected = WorkflowJob::query()
            ->whereKey($job->getKey())
            ->whereIn('status', $to->precedingStatuses())
            ->update($changes);

        if ($affected === 0) {
            return false;
        }

        $job->refresh();
        event(new JobStatusChanged($job));

        return true;
    }
}
