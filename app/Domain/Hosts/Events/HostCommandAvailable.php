<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Events;

use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A hint to one agent that it has a command waiting. The push is not the delivery: the command lives in the
 * database and the agent fetches it over HTTPS (and polls every 15 s), so a lost push only costs latency.
 */
final class HostCommandAvailable implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithBroadcasting;

    public function __construct(public readonly string $hostId, public readonly string $commandId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("host.{$this->hostId}")];
    }

    public function broadcastAs(): string
    {
        return 'command.available';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['command_id' => $this->commandId];
    }
}
