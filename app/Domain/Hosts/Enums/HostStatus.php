<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Enums;

enum HostStatus: string
{
    case Pending = 'pending';       // enrolled, no heartbeat yet
    case Online = 'online';
    case Offline = 'offline';       // heartbeats missed
    case Cordoned = 'cordoned';     // no new runners
    case Draining = 'draining';     // finishing current runners, then idle
    case Revoked = 'revoked';       // credential dead, terminal

    public function canAuthenticate(): bool
    {
        return $this !== self::Revoked;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
