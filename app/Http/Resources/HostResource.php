<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Hosts\Models\Host;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Host */
final class HostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'labels' => $this->labels,
            'arch' => $this->arch,
            'os' => $this->os,
            'docker_version' => $this->docker_version,
            'agent_version' => $this->agent_version,
            'cpu_total' => $this->cpu_total,
            'cpu_used' => $this->cpu_used,
            'memory_total_mb' => $this->memory_total_mb,
            'memory_used_mb' => $this->memory_used_mb,
            'disk_free_gb' => $this->disk_free_gb,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
