<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Models\User;

final class ReactivateUser
{
    public function __invoke(User $actor, User $user): void
    {
        $user->restore();

        Audit::record('user.reactivated', $user, ['email' => $user->email], $actor);
    }
}
