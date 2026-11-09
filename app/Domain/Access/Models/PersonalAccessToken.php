<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Policies\PersonalAccessTokenPolicy;
use App\Models\User;
use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[UsePolicy(PersonalAccessTokenPolicy::class)]
class PersonalAccessToken extends Model
{
    use HasUuid7;

    public const PREFIX = 'jog_';

    protected $guarded = [];

    protected $casts = [
        'role' => Role::class,
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /** Resolves a bearer token to its record, or null when unknown or expired. */
    public static function findUsable(string $plainToken): ?self
    {
        if (! str_starts_with($plainToken, self::PREFIX)) {
            return null;
        }

        $token = self::query()->where('token_hash', self::hash($plainToken))->first();

        return $token && ! $token->isExpired() ? $token : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Record usage at most once a minute to avoid a write per API call. */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(Carbon::now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
