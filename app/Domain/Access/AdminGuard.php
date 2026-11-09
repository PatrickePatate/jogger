<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;
use App\Support\Exceptions\LastAdmin;
use Illuminate\Support\Facades\DB;

/** Keeps at least one active global administrator at all times. */
final class AdminGuard
{
    private const LOCK_KEY = 7_406_714_502;

    /** Serialize admin-changing operations for the rest of the current transaction. */
    public static function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    /**
     * @throws LastAdmin when $user is the only active administrator
     */
    public static function assertNotLastAdmin(User $user): void
    {
        $others = User::query()->where('is_admin', true)->whereKeyNot($user->getKey())->exists();

        if (! $others) {
            throw new LastAdmin;
        }
    }
}
