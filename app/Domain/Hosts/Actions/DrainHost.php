<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Contracts\RunnerCensus;

/** Drain: stop placing runners, let the current ones finish, then rest as cordoned. */
final class DrainHost
{
    public function __construct(private readonly SetHostStatus $set, private readonly RunnerCensus $census) {}

    public function __invoke(Host $host): bool
    {
        $done = ($this->set)($host, [HostStatus::Pending, HostStatus::Online, HostStatus::Offline], HostStatus::Draining);
        if ($done) {
            Audit::record('host.draining', $host, ['name' => $host->name]);
            $this->settle($host);
        }

        return $done;
    }

    /** Called whenever the host's runner count may have dropped: a draining host with none left becomes cordoned. */
    public function settle(Host $host): bool
    {
        if ($host->status !== HostStatus::Draining || $this->census->activeOn($host) > 0) {
            return false;
        }

        return ($this->set)($host, [HostStatus::Draining], HostStatus::Cordoned);
    }
}
