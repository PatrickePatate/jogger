<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Contracts;

use App\Domain\Pools\Models\RunnerPool;

/** Supplies the environment variables (name => value) injected into a pool's runner containers. */
interface ResolvesSecrets
{
    /**
     * @return array<string, string>
     */
    public function envFor(RunnerPool $pool): array;
}
