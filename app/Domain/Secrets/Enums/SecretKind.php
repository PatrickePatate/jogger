<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Enums;

enum SecretKind: string
{
    case Env = 'env';           // environment variable of the runner container; name = variable name
    case Registry = 'registry'; // credentials for pulling images; name = registry host, value = JSON {username, password}

    public function label(): string
    {
        return match ($this) {
            self::Env => 'Environment variable',
            self::Registry => 'Registry credentials',
        };
    }
}
