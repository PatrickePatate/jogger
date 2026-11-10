<?php

declare(strict_types=1);

namespace App\Domain\Pools\Enums;

enum PoolStatus: string
{
    case Active = 'active';
    case Paused = 'paused';   // keeps its runners, starts no new ones
}
