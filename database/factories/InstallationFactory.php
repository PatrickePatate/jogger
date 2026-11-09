<?php

namespace Database\Factories;

use App\Domain\Github\Enums\AccountType;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Enums\RepositorySelection;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installation>
 */
class InstallationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'github_app_id' => GithubApp::factory(),
            'github_installation_id' => fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'account_login' => fake()->unique()->userName(),
            'account_type' => AccountType::Organization,
            'status' => InstallationStatus::Active,
            'repository_selection' => RepositorySelection::Selected,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => InstallationStatus::Suspended]);
    }

    public function forUser(): static
    {
        return $this->state(['account_type' => AccountType::User]);
    }
}
