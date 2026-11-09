<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Models\Team;
use App\Models\User;

final class RemoveTeamMember
{
    public function __invoke(User $actor, Team $team, User $user): void
    {
        $team->members()->detach($user->getKey());

        Audit::record('team.member_removed', $team, ['user' => $user->email], $actor);
    }
}
