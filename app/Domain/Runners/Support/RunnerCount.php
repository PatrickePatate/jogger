<?php

declare(strict_types=1);

namespace App\Domain\Runners\Support;

use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Contracts\RunnerCensus;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;

final class RunnerCount implements RunnerCensus
{
    public function activeOn(Host $host): int
    {
        return Runner::query()->where('host_id', $host->id)->inState(...RunnerState::present())->count();
    }
}
