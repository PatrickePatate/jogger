<?php

declare(strict_types=1);

namespace App\Domain\Runners\Actions;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ends a runner on purpose: mark it terminating, tell its host to stop the container, and remove its GitHub
 * registration. The runner becomes `gone` when the agent reports the container's exit (or at once if it never
 * reached a host). Safe to call on a runner that is already ending.
 */
final class TerminateRunner
{
    public function __construct(
        private readonly TransitionRunner $transition,
        private readonly HostDriver $driver,
        private readonly GithubGateway $github,
    ) {}

    public function __invoke(Runner $runner, string $reason = 'terminated'): bool
    {
        if (! ($this->transition)($runner, RunnerState::Terminating, ['exit_reason' => $reason])) {
            return false;
        }

        $this->deregister($runner);

        if ($runner->host === null) {
            ($this->transition)($runner, RunnerState::Gone);

            return true;
        }

        $this->driver->send($runner->host, 'runner.stop', ['runner_id' => $runner->id]);

        return true;
    }

    /** Best effort: GitHub removes ephemeral runners itself after a job, and the reconciler sweeps leftovers. */
    public function deregister(Runner $runner): void
    {
        if ($runner->github_runner_id === null) {
            return;
        }

        try {
            $this->github->deleteRunner($runner->pool->githubScope(), $runner->github_runner_id);
        } catch (Throwable $e) {
            Log::warning('could not delete GitHub runner', ['runner' => $runner->id, 'error' => $e->getMessage()]);
        }
    }
}
