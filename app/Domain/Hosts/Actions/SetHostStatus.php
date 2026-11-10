<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Events\HostStatusChanged;
use App\Domain\Hosts\Models\Host;

/** Conditional status change shared by the operator actions: applies only from the allowed states, broadcasts once. */
final class SetHostStatus
{
    /**
     * @param  list<HostStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    public function __invoke(Host $host, array $from, HostStatus $to, array $extra = []): bool
    {
        $changed = Host::query()->whereKey($host->id)
            ->whereIn('status', array_map(fn (HostStatus $s) => $s->value, $from))
            ->update(['status' => $to->value, 'updated_at' => now()] + $extra);

        if ($changed === 0) {
            return false;
        }

        event(new HostStatusChanged($host->refresh()));

        return true;
    }
}
