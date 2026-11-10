<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Hosts\Enums\CommandStatus;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use Illuminate\Support\Facades\DB;

/**
 * Revoke: terminal. The credential stops working on the very next request (HTTP and channel authorization both look
 * the host up each time), and commands that were never delivered are failed so nothing waits on them.
 */
final class RevokeHost
{
    public function __construct(private readonly SetHostStatus $set) {}

    public function __invoke(Host $host): bool
    {
        return DB::transaction(function () use ($host) {
            $from = [HostStatus::Pending, HostStatus::Online, HostStatus::Offline, HostStatus::Cordoned, HostStatus::Draining];
            $done = ($this->set)($host, $from, HostStatus::Revoked, ['credential_hash' => null, 'revoked_at' => now()]);
            if (! $done) {
                return false;
            }

            HostCommand::query()->where('host_id', $host->id)
                ->whereIn('status', [CommandStatus::Pending->value, CommandStatus::Sent->value])
                ->update(['status' => CommandStatus::Failed->value, 'result' => json_encode(['error' => 'host_revoked']), 'updated_at' => now()]);

            Audit::record('host.revoked', $host, ['name' => $host->name]);

            return true;
        });
    }
}
