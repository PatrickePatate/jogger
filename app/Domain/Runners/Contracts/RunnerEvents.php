<?php

declare(strict_types=1);

namespace App\Domain\Runners\Contracts;

use App\Domain\Hosts\Models\Host;

/** What the agent tells us about a runner's container. Implementations must tolerate repeats. */
interface RunnerEvents
{
    public function containerStarted(Host $host, string $runnerId): void;

    public function containerExited(Host $host, string $runnerId, ?int $exitCode, bool $oom): void;
}
