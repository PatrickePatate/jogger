<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Pools\Actions\TrimIdleRunners;
use App\Domain\Pools\Models\RunnerPool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class TrimIdleRunnersJob implements ShouldQueue
{
    use Queueable;

    /** Long enough for a runner that was just starting to become idle, short enough to meet "gone within a minute". */
    public const DELAY_SECONDS = 30;

    public int $tries = 3;

    public function __construct(public readonly string $poolId) {}

    public function handle(TrimIdleRunners $trim): void
    {
        $pool = RunnerPool::query()->find($this->poolId);
        if ($pool !== null) {
            $trim($pool);
        }
    }
}
