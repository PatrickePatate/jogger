<?php

namespace Database\Factories;

use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Runner>
 */
class RunnerFactory extends Factory
{
    protected $model = Runner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_id' => RunnerPool::factory(),
            'name' => Runner::NAME_PREFIX.Str::lower(Str::random(10)),
            'state' => RunnerState::Provisioning,
            'state_changed_at' => now(),
        ];
    }

    public function state_(RunnerState $state): static
    {
        return $this->state(['state' => $state]);
    }
}
