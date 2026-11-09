<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Setup;
use App\Models\User;
use App\Support\Exceptions\SetupAlreadyComplete;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the very first administrator. A transaction-scoped advisory lock serializes concurrent
 * attempts, so two simultaneous submissions can never both become "the first" admin.
 */
final class CreateInitialAdmin
{
    /** Arbitrary application-wide advisory lock key ("jogger" + 1). */
    private const LOCK_KEY = 7_406_714_501;

    /**
     * @throws SetupAlreadyComplete
     */
    public function __invoke(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            if (Setup::isComplete()) {
                throw new SetupAlreadyComplete;
            }

            $user = new User(['name' => $name, 'email' => Str::lower(trim($email)), 'password' => $password]);
            $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

            Audit::record('setup.admin_created', $user, ['email' => $user->email], $user);

            return $user;
        });
    }
}
