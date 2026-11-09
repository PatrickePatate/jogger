<?php

declare(strict_types=1);

namespace App\Domain\Access\Policies;

use App\Domain\Access\Models\PersonalAccessToken;
use App\Models\User;

final class PersonalAccessTokenPolicy
{
    /** Tokens belong to their creator; global admins can also remove anyone's. */
    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $token->user_id === $user->getKey() || $user->isAdmin();
    }
}
