<?php

declare(strict_types=1);

namespace App\Domain\Github\Enums;

enum AccountType: string
{
    case User = 'user';
    case Organization = 'org';

    /** GitHub reports "User" or "Organization" (also "Bot", treated as a user account). */
    public static function fromGithub(string $type): self
    {
        return strtolower($type) === 'organization' ? self::Organization : self::User;
    }
}
