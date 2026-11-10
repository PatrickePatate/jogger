<?php

declare(strict_types=1);

namespace App\Domain\Runners\Support;

use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Contracts\RunnerCensus;

/** Placeholder until the runners table exists. */
final class NoRunners implements RunnerCensus
{
    public function activeOn(Host $host): int
    {
        return 0;
    }
}
