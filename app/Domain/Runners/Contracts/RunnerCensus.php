<?php

declare(strict_types=1);

namespace App\Domain\Runners\Contracts;

use App\Domain\Hosts\Models\Host;

/** Answers "how many runners is this host still responsible for?" (anything not in a terminal state). */
interface RunnerCensus
{
    public function activeOn(Host $host): int;
}
