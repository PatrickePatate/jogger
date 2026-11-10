<?php

declare(strict_types=1);

namespace App\Domain\Pools\Data;

use Spatie\LaravelData\Data;

/** How many runners a pool keeps warm, its ceiling, and how long an unused runner may wait. */
final class ScalePolicyData extends Data
{
    public function __construct(
        public int $minIdle = 0,
        public int $maxRunners = 5,
        public int $idleTtlSeconds = 900,
    ) {}
}
