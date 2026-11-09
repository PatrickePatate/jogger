<?php

namespace Database\Factories;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'installation_id' => Installation::factory(),
            'github_repo_id' => fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'full_name' => fake()->userName().'/'.fake()->slug(2),
            'private' => true,
        ];
    }

    public function public(): static
    {
        return $this->state(['private' => false]);
    }
}
