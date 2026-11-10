<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Jobs\Models\WorkflowJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkflowJob */
final class JobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'github_job_id' => $this->github_job_id,
            'github_run_id' => $this->github_run_id,
            'repository' => $this->repository->full_name,
            'workflow_name' => $this->workflow_name,
            'job_name' => $this->job_name,
            'labels' => $this->labels,
            'status' => $this->status->value,
            'conclusion' => $this->conclusion?->value,
            'unmatched_reason' => $this->unmatched_reason,
            'pool_id' => $this->pool_id,
            'runner_id' => $this->runner_id,
            'head_branch' => $this->head_branch,
            'head_sha' => $this->head_sha,
            'actor' => $this->actor,
            'html_url' => $this->html_url,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
