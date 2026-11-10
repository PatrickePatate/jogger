<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Runners\Models\Runner;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired once per real state change of a runner. */
final class RunnerStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithBroadcasting;

    public function __construct(public readonly Runner $runner) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('runners'), new PrivateChannel("pool.{$this->runner->pool_id}")];
    }

    public function broadcastAs(): string
    {
        return 'runner.state-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $runner = $this->runner;

        return [
            'id' => $runner->id,
            'pool_id' => $runner->pool_id,
            'host_id' => $runner->host_id,
            'name' => $runner->name,
            'state' => $runner->state->value,
            'exit_reason' => $runner->exit_reason,
            'exit_code' => $runner->exit_code,
        ];
    }
}
