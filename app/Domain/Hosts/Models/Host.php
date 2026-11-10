<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Models;

use App\Domain\Hosts\Enums\HostStatus;
use App\Support\Concerns\HasUuid7;
use App\Support\Pg\PgTextArray;
use Database\Factories\HostFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property list<string> $labels
 */
#[UseFactory(HostFactory::class)]
class Host extends Model
{
    /** @use HasFactory<HostFactory> */
    use HasFactory, HasUuid7, SoftDeletes;

    public const CREDENTIAL_PREFIX = 'jha_';

    protected $guarded = [];

    protected $hidden = ['credential_hash'];

    protected $casts = [
        'status' => HostStatus::class,
        'labels' => PgTextArray::class,
        'cpu_total' => 'float',
        'cpu_used' => 'float',
        'memory_total_mb' => 'integer',
        'memory_used_mb' => 'integer',
        'disk_free_gb' => 'integer',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function hashCredential(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Resolves an agent bearer credential to a host that may still authenticate (not revoked or deleted). */
    public static function findByCredential(string $plain): ?self
    {
        if (! str_starts_with($plain, self::CREDENTIAL_PREFIX)) {
            return null;
        }

        $host = self::query()->where('credential_hash', self::hashCredential($plain))->first();

        return $host !== null && $host->status->canAuthenticate() ? $host : null;
    }
}
