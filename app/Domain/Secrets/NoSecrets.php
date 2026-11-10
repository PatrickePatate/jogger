<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Secrets\Contracts\ResolvesSecrets;

/** Placeholder until the secrets store exists. */
final class NoSecrets implements ResolvesSecrets
{
    public function envFor(RunnerPool $pool): array
    {
        return [];
    }
}
