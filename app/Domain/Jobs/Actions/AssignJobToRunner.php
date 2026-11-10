<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Runners\Actions\TransitionRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;

/** GitHub says which runner picked a job up: link the two and mark the runner busy. Not our runner → nothing. */
final class AssignJobToRunner
{
    public function __construct(private readonly TransitionRunner $transition) {}

    public function __invoke(WorkflowJob $job, ?string $runnerName, ?int $githubRunnerId = null): ?Runner
    {
        $runner = null;
        if ($runnerName !== null && str_starts_with($runnerName, Runner::NAME_PREFIX)) {
            $runner = Runner::query()->where('name', $runnerName)->first();
        }
        if ($runner === null && $githubRunnerId !== null && $job->pool_id !== null) {
            $runner = Runner::query()->where('pool_id', $job->pool_id)->where('github_runner_id', $githubRunnerId)->first();
        }
        if ($runner === null) {
            return null;
        }

        $job->forceFill(['runner_id' => $runner->id, 'pool_id' => $runner->pool_id, 'unmatched_reason' => null])->save();
        ($this->transition)($runner, RunnerState::Busy);

        return $runner;
    }
}
