<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Events;

use App\Domain\Hosts\Models\Host;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A host's status changed (connected, went offline, came back…). Hosts are shared infrastructure: every signed-in user may listen. */
final class HostStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithBroadcasting;

    public function __construct(public readonly Host $host) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('hosts')];
    }

    public function broadcastAs(): string
    {
        return 'host.status-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->host->id, 'name' => $this->host->name, 'status' => $this->host->status->value];
    }
}
