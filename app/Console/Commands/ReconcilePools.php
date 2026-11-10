<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pools\Actions\ReconcilePoolCapacity;
use App\Domain\Pools\Enums\PoolStatus;
use App\Domain\Pools\Models\RunnerPool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:reconcile-pools')]
#[Description('Start runners for pools below their warm minimum or with jobs waiting')]
final class ReconcilePools extends Command
{
    public function handle(ReconcilePoolCapacity $reconcile): int
    {
        $started = 0;
        RunnerPool::query()->where('status', PoolStatus::Active->value)->with('installation')->get()
            ->each(function (RunnerPool $pool) use ($reconcile, &$started) {
                $started += $reconcile($pool);
            });

        $this->info("Started {$started} runner(s).");

        return self::SUCCESS;
    }
}
