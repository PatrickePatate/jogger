<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Models;

use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentToken extends Model
{
    use HasUuid7;

    public const PREFIX = 'jhe_';

    public const TTL_MINUTES = 60;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Host, $this>
     */
    public function usedByHost(): BelongsTo
    {
        return $this->belongsTo(Host::class, 'used_by_host_id');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
