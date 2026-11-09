<?php

namespace Database\Factories;

use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowJob>
 */
class WorkflowJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $jobId = fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999);

        return [
            'provider' => 'github',
            'github_job_id' => $jobId,
            'github_run_id' => fake()->numberBetween(1_000_000, 99_999_999),
            'run_attempt' => 1,
            'repository_id' => Repository::factory(),
            'workflow_name' => 'CI',
            'job_name' => 'build',
            'status' => JobStatus::Queued,
            'labels' => ['self-hosted', 'linux', 'x64'],
            'head_sha' => fake()->sha1(),
            'head_branch' => 'main',
            'actor' => fake()->userName(),
            'html_url' => "https://github.com/acme/api/actions/runs/1/job/{$jobId}",
            'queued_at' => now()->subSeconds(30),
        ];
    }

    public function running(): static
    {
        return $this->state(['status' => JobStatus::InProgress, 'started_at' => now()->subSeconds(10)]);
    }

    public function completed(JobConclusion $conclusion = JobConclusion::Success): static
    {
        return $this->state([
            'status' => JobStatus::Completed,
            'conclusion' => $conclusion,
            'started_at' => now()->subMinutes(3),
            'completed_at' => now()->subMinute(),
        ]);
    }
}
