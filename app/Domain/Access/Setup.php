<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;

/** First-run state: Jogger is "set up" as soon as any user (even a soft-deleted one) exists. */
final class Setup
{
    public static function isComplete(): bool
    {
        return User::withTrashed()->exists();
    }
}
