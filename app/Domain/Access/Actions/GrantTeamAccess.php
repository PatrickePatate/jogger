<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Contracts\Grantable;
use App\Domain\Access\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Gives a team access to an installation or pool (and takes it back). */
final class GrantTeamAccess
{
    public function __invoke(User $actor, Team $team, Grantable&Model $resource): void
    {
        $team->grant($resource);

        Audit::record('team.access_granted', $team, ['type' => $resource->grantableType(), 'resource_id' => $resource->getKey()], $actor);
    }

    public function revoke(User $actor, Team $team, Grantable&Model $resource): void
    {
        $team->revoke($resource);

        Audit::record('team.access_revoked', $team, ['type' => $resource->grantableType(), 'resource_id' => $resource->getKey()], $actor);
    }
}
