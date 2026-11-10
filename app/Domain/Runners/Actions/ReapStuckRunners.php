<?php

declare(strict_types=1);

namespace App\Domain\Runners\Actions;

use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Jobs\Actions\ScheduleJob;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;

/**
 * The safety net for runners whose normal ending never came. Every rule is a conditional transition, so running
 * the reaper twice in a row (or two reapers at once) does nothing the second time.
 */
final class ReapStuckRunners
{
    /** A host must be offline this long before its runners are written off. */
    public const HOST_LOST_AFTER_SECONDS = 300;

    /** A stop that was never confirmed by the agent is assumed done after this long. */
    public const TERMINATING_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly TransitionRunner $transition,
        private readonly TerminateRunner $terminate,
        private readonly HostDriver $driver,
        private readonly ScheduleJob $schedule,
    ) {}

    /**
     * @return array{provision_timeout: int, host_lost: int, idle_timeout: int, stop_unconfirmed: int}
     */
    public function __invoke(): array
    {
        return [
            'host_lost' => $this->hostLost(),
            'provision_timeout' => $this->provisionTimeout(),
            'idle_timeout' => $this->idleTimeout(),
            'stop_unconfirmed' => $this->stopUnconfirmed(),
        ];
    }

    /** The container never reported in: stop whatever exists, free the slot, and try again for the waiting job. */
    private function provisionTimeout(): int
    {
        $cutoff = now()->subSeconds((int) config('jogger.runner_provision_timeout'));
        $count = 0;
        $pools = [];

        Runner::query()->inState(RunnerState::Provisioning)->where('state_changed_at', '<', $cutoff)->get()
            ->each(function (Runner $runner) use (&$count, &$pools) {
                if (! ($this->transition)($runner, RunnerState::Failed, ['exit_reason' => 'provision_timeout'])) {
                    return;
                }
                $count++;
                $pools[$runner->pool_id] = true;
                $this->terminate->deregister($runner);
                if ($runner->host !== null && $runner->host->status !== HostStatus::Revoked) {
                    $this->driver->send($runner->host, 'runner.stop', ['runner_id' => $runner->id]);
                }
            });

        // The jobs those runners were meant for are still queued: schedule the oldest of each pool again.
        foreach (array_keys($pools) as $poolId) {
            $job = WorkflowJob::query()->where('pool_id', $poolId)->where('status', JobStatus::Queued->value)->orderBy('queued_at')->first();
            if ($job !== null) {
                ($this->schedule)($job);
            }
        }

        return $count;
    }

    private function hostLost(): int
    {
        $cutoff = now()->subSeconds(self::HOST_LOST_AFTER_SECONDS);
        $count = 0;

        Runner::query()->inState(...RunnerState::present())
            ->whereHas('host', fn ($q) => $q->where(fn ($q) => $q
                ->where('status', HostStatus::Revoked->value)
                ->orWhere(fn ($q) => $q->where('status', HostStatus::Offline->value)->where('last_seen_at', '<', $cutoff))))
            ->get()
            ->each(function (Runner $runner) use (&$count) {
                if (($this->transition)($runner, RunnerState::Failed, ['exit_reason' => 'host_lost'])) {
                    $count++;
                    $this->terminate->deregister($runner);
                }
            });

        return $count;
    }

    /** Idle longer than the pool allows, keeping the warm minimum (the oldest go first). */
    private function idleTimeout(): int
    {
        $count = 0;

        RunnerPool::query()->whereIn('id', Runner::query()->inState(RunnerState::Idle)->select('pool_id'))->get()
            ->each(function (RunnerPool $pool) use (&$count) {
                $idle = Runner::query()->where('pool_id', $pool->id)->inState(RunnerState::Idle)->orderBy('state_changed_at')->get();
                $removable = $idle->count() - $pool->min_idle;
                $cutoff = now()->subSeconds($pool->idle_ttl_seconds);

                foreach ($idle as $runner) {
                    if ($removable <= 0 || $runner->state_changed_at->gte($cutoff)) {
                        break;
                    }
                    if (($this->terminate)($runner, 'idle_timeout')) {
                        $count++;
                        $removable--;
                    }
                }
            });

        return $count;
    }

    private function stopUnconfirmed(): int
    {
        $count = 0;

        Runner::query()->inState(RunnerState::Terminating)
            ->where('state_changed_at', '<', now()->subSeconds(self::TERMINATING_TIMEOUT_SECONDS))->get()
            ->each(function (Runner $runner) use (&$count) {
                $count += (int) ($this->transition)($runner, RunnerState::Gone);
            });

        return $count;
    }
}
