<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Contracts;

use App\Domain\Pools\Models\RunnerPool;

/** Supplies what a pool's runners need that must stay secret: environment variables and image registry credentials. */
interface ResolvesSecrets
{
    /**
     * @return array<string, string>
     */
    public function envFor(RunnerPool $pool): array;

    /**
     * @return array{server: string, username: string, password: string}|null
     */
    public function registryAuthFor(RunnerPool $pool): ?array;
}
