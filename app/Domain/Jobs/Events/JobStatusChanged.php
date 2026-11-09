<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Events;

use App\Domain\Jobs\Models\WorkflowJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired once per real status change of a job (never for stale or duplicate webhooks). */
final class JobStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithBroadcasting;

    public function __construct(public readonly WorkflowJob $job) {}

    /**
     * `jobs` is the global feed (admins only); the repository and job channels are visible to anyone who can see the repo.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('jobs'),
            new PrivateChannel("repository.{$this->job->repository_id}"),
            new PrivateChannel("job.{$this->job->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'job.status-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $job = $this->job;

        return [
            'id' => $job->id,
            'repository_id' => $job->repository_id,
            'job_name' => $job->job_name,
            'workflow_name' => $job->workflow_name,
            'status' => $job->status->value,
            'conclusion' => $job->conclusion?->value,
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
        ];
    }
}
