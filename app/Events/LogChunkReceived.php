<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** A piece of a job's log, already masked, for browsers following the job live. */
final class LogChunkReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithBroadcasting;

    /** Pusher-protocol messages are capped (10 KB by default), so a batch is broadcast in slices. */
    public const MAX_BYTES = 8000;

    public function __construct(
        public readonly string $jobId,
        public readonly int $seq,
        public readonly int $part,
        public readonly string $data,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("job.{$this->jobId}")];
    }

    public function broadcastAs(): string
    {
        return 'log.chunk';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['job_id' => $this->jobId, 'seq' => $this->seq, 'part' => $this->part, 'data' => $this->data];
    }
}
