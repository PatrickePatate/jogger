<?php

declare(strict_types=1);

namespace App\Domain\Access\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * For models implementing Grantable: Model::query()->visibleTo($user) limits rows to
 * those a user's teams have been granted. Global admins see everything.
 */
trait VisibleTo
{
    public function grantableType(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn(
            $this->qualifyColumn($this->getKeyName()),
            fn ($sub) => $sub->select('team_grants.grantable_id')
                ->from('team_grants')
                ->join('teams', 'teams.id', '=', 'team_grants.team_id')
                ->join('team_user', 'team_user.team_id', '=', 'teams.id')
                ->where('team_user.user_id', $user->getKey())
                ->where('team_grants.grantable_type', $this->grantableType())
                ->whereNull('teams.deleted_at'),
        );
    }
}
