<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;

/** Cordon: no new runners are placed on the host; running ones are left alone. `uncordon` puts it back in service. */
final class CordonHost
{
    public function __construct(private readonly SetHostStatus $set) {}

    public function __invoke(Host $host): bool
    {
        $done = ($this->set)($host, [HostStatus::Pending, HostStatus::Online, HostStatus::Offline, HostStatus::Draining], HostStatus::Cordoned);
        if ($done) {
            Audit::record('host.cordoned', $host, ['name' => $host->name]);
        }

        return $done;
    }

    public function uncordon(Host $host): bool
    {
        // The real state is whatever the heartbeats say: recently seen means online.
        $alive = $host->last_seen_at !== null && $host->last_seen_at->gt(now()->subSeconds((int) config('jogger.host_offline_after')));
        $to = $alive ? HostStatus::Online : ($host->last_seen_at === null ? HostStatus::Pending : HostStatus::Offline);

        $done = ($this->set)($host, [HostStatus::Cordoned, HostStatus::Draining], $to);
        if ($done) {
            Audit::record('host.uncordoned', $host, ['name' => $host->name]);
        }

        return $done;
    }
}
