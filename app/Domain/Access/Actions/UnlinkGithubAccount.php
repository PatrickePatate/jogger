<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class UnlinkGithubAccount
{
    /**
     * @throws AuthorizationException when the account has no password (unlinking would lock the user out)
     */
    public function __invoke(User $user): void
    {
        if ($user->password === null) {
            throw new AuthorizationException('Set a password before unlinking GitHub: use “Forgot password” on the sign-in page.');
        }

        $login = $user->github_login;
        $user->forceFill(['github_id' => null, 'github_login' => null])->save();

        Audit::record('auth.github_unlinked', $user, ['github_login' => $login], $user);
    }
}
