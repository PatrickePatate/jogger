<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pools\Models\RunnerPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RunnerPool */
final class PoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'installation_id' => $this->installation_id,
            'name' => $this->name,
            'scope' => $this->scope->value,
            'scope_target' => $this->scope_target,
            'runner_group_id' => $this->runner_group_id,
            'labels' => $this->labels,
            'image' => $this->image,
            'cpu_limit' => $this->cpu_limit,
            'memory_limit_mb' => $this->memory_limit_mb,
            'min_idle' => $this->min_idle,
            'max_runners' => $this->max_runners,
            'idle_ttl_seconds' => $this->idle_ttl_seconds,
            'docker_mode' => $this->docker_mode->value,
            'persist_tool_cache' => $this->persist_tool_cache,
            'allowed_host_labels' => $this->allowed_host_labels,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
