<?php

declare(strict_types=1);

namespace App\Domain\Pools\Policies;

use App\Domain\Access\Enums\Role;
use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Models\RunnerPool;
use App\Models\User;

/** Viewers see pools; operators create and change them. The role comes from a grant on the pool or its installation. */
final class RunnerPoolPolicy
{
    public function view(User $user, RunnerPool $pool): bool
    {
        return $this->role($user, $pool) !== null;
    }

    public function update(User $user, RunnerPool $pool): bool
    {
        return (bool) $this->role($user, $pool)?->atLeast(Role::Operator);
    }

    public function delete(User $user, RunnerPool $pool): bool
    {
        return $this->update($user, $pool);
    }

    public function createIn(User $user, Installation $installation): bool
    {
        return (bool) $user->roleFor($installation)?->atLeast(Role::Operator);
    }

    private function role(User $user, RunnerPool $pool): ?Role
    {
        return Role::highest(array_filter([$user->roleFor($pool), $user->roleFor($pool->installation)]));
    }
}
