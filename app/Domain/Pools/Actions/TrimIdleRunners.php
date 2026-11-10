<?php

declare(strict_types=1);

namespace App\Domain\Pools\Actions;

use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolDemand;
use App\Domain\Runners\Actions\TerminateRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;

/**
 * Removes idle runners nobody is waiting for: those beyond the pool's queued jobs plus its warm minimum.
 * Used after a job is cancelled before any runner picked it up.
 */
final class TrimIdleRunners
{
    public function __construct(private readonly TerminateRunner $terminate, private readonly PoolDemand $demand) {}

    /** @return int how many runners were terminated */
    public function __invoke(RunnerPool $pool, string $reason = 'job_cancelled'): int
    {
        $surplus = -$this->demand->deficit($pool);
        if ($surplus <= 0) {
            return 0;
        }

        // Newest first: the oldest idle runners are the most likely to be picked by a job already on its way.
        $victims = Runner::query()->where('pool_id', $pool->id)->inState(RunnerState::Idle)->orderByDesc('created_at')->limit($surplus)->get();
        $count = 0;
        foreach ($victims as $runner) {
            $count += (int) ($this->terminate)($runner, $reason);
        }

        return $count;
    }
}
