<?php

namespace Database\Factories;

use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Host>
 */
class HostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'status' => HostStatus::Online,
            'labels' => [],
            'arch' => 'x64',
            'os' => 'linux',
            'docker_version' => '27.3.1',
            'cpu_total' => 8,
            'memory_total_mb' => 16384,
            'disk_free_gb' => 200,
            'agent_version' => '0.1.0',
            'last_seen_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(['status' => HostStatus::Revoked, 'revoked_at' => now()]);
    }
}
