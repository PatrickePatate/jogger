<?php

namespace Database\Factories;

use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Models\RunnerPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunnerPool>
 */
class RunnerPoolFactory extends Factory
{
    protected $model = RunnerPool::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'installation_id' => Installation::factory(),
            'name' => 'pool-'.fake()->unique()->lexify('??????'),
            'scope' => 'organization',
            'scope_target' => 'acme',
            'labels' => ['self-hosted', 'linux', 'x64'],
            'allowed_host_labels' => [],
            'image' => 'ghcr.io/patrickepatate/jogger-runner:latest',
            'min_idle' => 0,
            'max_runners' => 5,
            'idle_ttl_seconds' => 900,
            'docker_mode' => 'none',
            'status' => 'active',
        ];
    }

    public function paused(): static
    {
        return $this->state(['status' => 'paused']);
    }
}
