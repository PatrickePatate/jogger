<?php

declare(strict_types=1);

namespace App\Domain\Secrets;

use App\Domain\Access\Enums\Role;
use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Secrets\Enums\SecretScope;
use App\Domain\Secrets\Models\Secret;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Who may see that a secret exists, and who may set or delete it. Nobody may read a value back. */
final class SecretAccess
{
    /** Global: administrators. Installation: operators of it. Pool: operators of the pool. */
    public static function canManage(User $user, SecretScope $scope, ?string $scopeId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return match ($scope) {
            SecretScope::Global => false,
            SecretScope::Installation => ($installation = Installation::query()->visibleTo($user)->find($scopeId)) !== null
                && (bool) $user->roleFor($installation)?->atLeast(Role::Operator),
            SecretScope::Pool => ($pool = RunnerPool::query()->visibleTo($user)->find($scopeId)) !== null
                && $user->can('update', $pool),
        };
    }

    /**
     * @return Builder<Secret>
     */
    public static function visibleTo(User $user): Builder
    {
        $query = Secret::query();
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('scope_type', SecretScope::Pool->value)
                ->whereIn('scope_id', RunnerPool::query()->visibleTo($user)->select('runner_pools.id')))
            ->orWhere(fn (Builder $q) => $q->where('scope_type', SecretScope::Installation->value)
                ->whereIn('scope_id', Installation::query()->visibleTo($user)->select('installations.id'))));
    }
}
