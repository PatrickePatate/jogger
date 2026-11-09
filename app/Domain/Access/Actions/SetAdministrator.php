<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AdminGuard;
use App\Domain\Access\Audit;
use App\Models\User;
use App\Support\Exceptions\LastAdmin;
use Illuminate\Support\Facades\DB;

final class SetAdministrator
{
    /**
     * @throws LastAdmin when demoting the only administrator
     */
    public function __invoke(User $actor, User $user, bool $administrator): void
    {
        DB::transaction(function () use ($actor, $user, $administrator): void {
            AdminGuard::lock();

            $user->refresh();
            if ($user->isAdmin() === $administrator) {
                return;
            }

            if (! $administrator) {
                AdminGuard::assertNotLastAdmin($user);
            }

            $user->forceFill(['is_admin' => $administrator])->save();

            Audit::record($administrator ? 'user.admin_granted' : 'user.admin_revoked', $user, ['email' => $user->email], $actor);
        });
    }
}
