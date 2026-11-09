<?php

namespace App\Models;

use App\Domain\Access\Contracts\Grantable;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\PersonalAccessToken;
use App\Domain\Access\Models\Team;
use App\Support\Concerns\HasUuid7;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuid7, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role');
    }

    /**
     * @return HasMany<PersonalAccessToken, $this>
     */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    /** The most powerful role this user holds anywhere (the ceiling for their API tokens). */
    public function maxRole(): ?Role
    {
        if ($this->isAdmin()) {
            return Role::Admin;
        }

        return Role::highest(
            DB::table('team_user')
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->where('team_user.user_id', $this->getKey())
                ->whereNull('teams.deleted_at')
                ->pluck('team_user.role'),
        );
    }

    /** Global administrators see and can do everything. */
    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * The user's role over a resource: Admin for global admins, otherwise the highest
     * role among their (non-deleted) teams that were granted the resource; null = no access.
     */
    public function roleFor(Grantable&Model $grantable): ?Role
    {
        if ($this->isAdmin()) {
            return Role::Admin;
        }

        $roles = DB::table('team_user')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->join('team_grants', 'team_grants.team_id', '=', 'teams.id')
            ->where('team_user.user_id', $this->getKey())
            ->where('team_grants.grantable_type', $grantable->grantableType())
            ->where('team_grants.grantable_id', $grantable->getKey())
            ->whereNull('teams.deleted_at')
            ->pluck('team_user.role');

        return Role::highest($roles);
    }
}
