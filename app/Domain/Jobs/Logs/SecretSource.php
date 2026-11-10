<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Logs;

/** Supplies the secret values that must never appear in a runner's stored or broadcast log. */
interface SecretSource
{
    /**
     * @return list<string>
     */
    public function forRunner(string $runnerId): array;
}
