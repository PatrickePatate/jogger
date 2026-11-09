<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\Role;
use App\Models\User;
use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Invitation extends Model
{
    use HasUuid7;

    public const VALID_DAYS = 7;

    protected $guarded = [];

    protected $casts = [
        'is_admin' => 'boolean',
        'role' => Role::class,
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** Signed link, valid until the invitation expires. */
    public function acceptUrl(): string
    {
        return URL::temporarySignedRoute('invitations.accept', $this->expires_at, ['invitation' => $this->id]);
    }
}
