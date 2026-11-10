<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Runners\Models\Runner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Runner */
final class RunnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'state' => $this->state->value,
            'pool_id' => $this->pool_id,
            'host_id' => $this->host_id,
            'github_runner_id' => $this->github_runner_id,
            'exit_reason' => $this->exit_reason,
            'exit_code' => $this->exit_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
