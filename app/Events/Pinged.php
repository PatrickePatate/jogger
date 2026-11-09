<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Dev-only realtime smoke test (see /_ui). Broadcast immediately, no queue worker needed. */
final class Pinged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('dev.ping')];
    }

    public function broadcastAs(): string
    {
        return 'Pinged';
    }
}
