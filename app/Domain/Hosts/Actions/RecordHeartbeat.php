<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Events\HostStatusChanged;
use App\Domain\Hosts\Models\Host;

final class RecordHeartbeat
{
    public function __construct(private readonly DrainHost $drain) {}

    /**
     * Stores the host's latest capacity facts and marks it alive. A pending or offline host becomes online;
     * cordoned and draining hosts keep their operator-chosen state. Broadcasts only when the status changes.
     *
     * @param  array<string, mixed>  $facts
     */
    public function __invoke(Host $host, array $facts): Host
    {
        $host->fill(array_intersect_key($facts, array_flip([
            'arch', 'os', 'docker_version', 'agent_version',
            'cpu_total', 'cpu_used', 'memory_total_mb', 'memory_used_mb', 'disk_free_gb',
        ])));
        $host->last_seen_at = now();

        $changed = in_array($host->status, [HostStatus::Pending, HostStatus::Offline], true);
        if ($changed) {
            $host->status = HostStatus::Online;
        }

        $host->save();

        if ($changed) {
            event(new HostStatusChanged($host));
        }

        // A draining host whose last runner is gone settles into cordoned.
        $this->drain->settle($host);

        return $host;
    }
}
