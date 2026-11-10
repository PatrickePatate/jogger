<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Hosts\Events\HostCommandAvailable;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;

/** Transactional outbox: the command row is the delivery; the broadcast is only a hint, sent after commit. */
final class DispatchHostCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(Host $host, string $type, array $payload = []): HostCommand
    {
        $command = HostCommand::query()->create([
            'host_id' => $host->id,
            'type' => $type,
            'payload' => $payload,
            // Set from the app clock (not the column default) so "due" is always compared on one clock.
            'available_at' => now(),
        ]);

        event(new HostCommandAvailable($host->id, $command->id));

        return $command;
    }
}
