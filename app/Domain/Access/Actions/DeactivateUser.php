<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AdminGuard;
use App\Domain\Access\Audit;
use App\Models\User;
use App\Support\Exceptions\LastAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Soft-deletes the user (existing sessions stop working) and revokes their API tokens. */
final class DeactivateUser
{
    /**
     * @throws AuthorizationException when deactivating yourself
     * @throws LastAdmin when the user is the only administrator
     */
    public function __invoke(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw new AuthorizationException('You cannot deactivate your own account. Ask another administrator.');
        }

        DB::transaction(function () use ($actor, $user): void {
            AdminGuard::lock();

            if ($user->isAdmin()) {
                AdminGuard::assertNotLastAdmin($user);
            }

            $user->accessTokens()->delete();
            $user->delete();

            Audit::record('user.deactivated', $user, ['email' => $user->email], $actor);
        });
    }
}
