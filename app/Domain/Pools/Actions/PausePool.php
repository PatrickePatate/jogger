<?php

declare(strict_types=1);

namespace App\Domain\Pools\Actions;

use App\Domain\Access\Audit;
use App\Domain\Pools\Enums\PoolStatus;
use App\Domain\Pools\Models\RunnerPool;

/** A paused pool keeps its running jobs but starts no new runners. */
final class PausePool
{
    public function __invoke(RunnerPool $pool): void
    {
        $this->set($pool, PoolStatus::Paused, 'pool.paused');
    }

    public function resume(RunnerPool $pool): void
    {
        $this->set($pool, PoolStatus::Active, 'pool.resumed');
    }

    private function set(RunnerPool $pool, PoolStatus $status, string $action): void
    {
        if ($pool->status === $status) {
            return;
        }
        $pool->update(['status' => $status]);
        Audit::record($action, $pool, ['name' => $pool->name]);
    }
}
