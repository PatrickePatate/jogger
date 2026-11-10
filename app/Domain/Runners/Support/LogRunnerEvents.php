<?php

declare(strict_types=1);

namespace App\Domain\Runners\Support;

use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Contracts\RunnerEvents;
use Illuminate\Support\Facades\Log;

/** Only records the event; replaced by the runner state machine. */
final class LogRunnerEvents implements RunnerEvents
{
    public function containerStarted(Host $host, string $runnerId): void
    {
        Log::info('runner container started', ['host' => $host->id, 'runner' => $runnerId]);
    }

    public function containerExited(Host $host, string $runnerId, ?int $exitCode, bool $oom): void
    {
        Log::info('runner container exited', ['host' => $host->id, 'runner' => $runnerId, 'exit_code' => $exitCode, 'oom' => $oom]);
    }
}
