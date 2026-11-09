<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Models\User;
use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only record of who did what. Rows are never edited; retention pruning deletes by query. */
class AuditLog extends Model
{
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
