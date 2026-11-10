<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Enums;

enum SecretScope: string
{
    case Global = 'global';
    case Installation = 'installation';
    case Pool = 'pool';

    /** Higher wins when the same name exists at several scopes. */
    public function precedence(): int
    {
        return match ($this) {
            self::Global => 0,
            self::Installation => 1,
            self::Pool => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Everywhere',
            self::Installation => 'Installation',
            self::Pool => 'Pool',
        };
    }
}
