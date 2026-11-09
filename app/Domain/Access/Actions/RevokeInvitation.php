<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Models\Invitation;
use App\Models\User;

final class RevokeInvitation
{
    public function __invoke(User $actor, Invitation $invitation): void
    {
        Audit::record('user.invitation_revoked', $invitation, ['email' => $invitation->email], $actor);

        $invitation->delete();
    }
}
