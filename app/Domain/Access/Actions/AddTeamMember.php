<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Models\User;

final class AddTeamMember
{
    public function __invoke(User $actor, Team $team, User $user, Role $role): void
    {
        $team->addMember($user, $role);

        Audit::record('team.member_added', $team, ['user' => $user->email, 'role' => $role->value], $actor);
    }
}
