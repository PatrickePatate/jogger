<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Audit::record('token.created', $token, ['name' => 'CI'])
 * Never put secrets (passwords, tokens, keys) in $metadata.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, ?Model $target = null, array $metadata = [], ?User $actor = null): AuditLog
    {
        $actor ??= Auth::user();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip' => request()->ip(),
        ]);
    }
}
