<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** Changes a member's role within a team. Takes effect on their next request (roles are never cached). */
final class ChangeRole
{
    /**
     * @throws ModelNotFoundException when the user is not a member of the team
     */
    public function __invoke(User $actor, Team $team, User $user, Role $role): void
    {
        $from = DB::table('team_user')->where('team_id', $team->getKey())->where('user_id', $user->getKey())->value('role');

        if ($from === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
        }

        if ($from === $role->value) {
            return;
        }

        $team->members()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

        Audit::record('user.role_changed', $user, ['team' => $team->name, 'from' => $from, 'to' => $role->value], $actor);
    }
}
