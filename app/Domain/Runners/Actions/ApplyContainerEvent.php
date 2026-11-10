<?php

declare(strict_types=1);

namespace App\Domain\Runners\Actions;

use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Contracts\RunnerEvents;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;

/** Turns what the agent saw (a container started or exited) into runner state. Repeats and unknown runners are no-ops. */
final class ApplyContainerEvent implements RunnerEvents
{
    public function __construct(private readonly TransitionRunner $transition, private readonly TerminateRunner $terminate) {}

    public function containerStarted(Host $host, string $runnerId): void
    {
        $runner = $this->find($host, $runnerId);
        if ($runner !== null) {
            ($this->transition)($runner, RunnerState::Idle);
        }
    }

    public function containerExited(Host $host, string $runnerId, ?int $exitCode, bool $oom): void
    {
        $runner = $this->find($host, $runnerId);
        if ($runner === null || $runner->state->isTerminal()) {
            return;
        }

        $was = $runner->state;
        $code = ['exit_code' => $exitCode];

        if ($oom) {
            ($this->transition)($runner, RunnerState::Failed, $code + ['exit_reason' => 'oom']);
        } elseif ($was === RunnerState::Terminating) {
            ($this->transition)($runner, RunnerState::Gone, $code);
        } elseif ($exitCode === 0) {
            // A clean exit nobody asked for: the runner finished its one job (or was told to leave by GitHub).
            ($this->transition)($runner, RunnerState::Terminating, ['exit_reason' => 'completed']);
            ($this->transition)($runner, RunnerState::Gone, $code);
        } else {
            ($this->transition)($runner, RunnerState::Failed, $code + ['exit_reason' => 'container_exited']);
        }

        // It never took a job, so GitHub still lists it.
        if ($was === RunnerState::Provisioning || $was === RunnerState::Idle) {
            $this->terminate->deregister($runner);
        }
    }

    private function find(Host $host, string $runnerId): ?Runner
    {
        return Runner::query()->whereKey($runnerId)->where('host_id', $host->id)->first();
    }
}
