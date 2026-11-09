<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\Access\Contracts\Grantable;
use App\Domain\Access\Enums\Role;
use App\Models\User;
use App\Support\Concerns\HasUuid7;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug'])]
#[UseFactory(TeamFactory::class)]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUuid7, SoftDeletes;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    /**
     * @return HasMany<TeamGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(TeamGrant::class);
    }

    public function addMember(User $user, Role $role): void
    {
        $this->members()->syncWithoutDetaching([$user->getKey() => ['role' => $role->value]]);
    }

    public function grant(Grantable&Model $grantable): TeamGrant
    {
        return $this->grants()->firstOrCreate([
            'grantable_type' => $grantable->grantableType(),
            'grantable_id' => $grantable->getKey(),
        ]);
    }

    public function revoke(Grantable&Model $grantable): void
    {
        $this->grants()
            ->where('grantable_type', $grantable->grantableType())
            ->where('grantable_id', $grantable->getKey())
            ->delete();
    }
}
