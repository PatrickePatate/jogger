<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Models\Team;
use App\Models\User;

/** Soft-deletes the team: its members and grants stop counting immediately. */
final class DeleteTeam
{
    public function __invoke(User $actor, Team $team): void
    {
        Audit::record('team.deleted', $team, ['name' => $team->name], $actor);

        $team->delete();
    }
}
