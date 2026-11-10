<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Logs;

final class NoSecrets implements SecretSource
{
    public function forRunner(string $runnerId): array
    {
        return [];
    }
}
