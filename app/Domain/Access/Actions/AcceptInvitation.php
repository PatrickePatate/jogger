<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Models\Invitation;
use App\Models\User;
use App\Support\Exceptions\InvitationInvalid;
use App\Support\Exceptions\UserAlreadyExists;
use Illuminate\Support\Facades\DB;

final class AcceptInvitation
{
    /**
     * @throws InvitationInvalid when the invitation was used, revoked or has expired
     * @throws UserAlreadyExists when the address registered in the meantime
     */
    /**
     * @param  string|null  $password  null only for accounts created through GitHub (no local password)
     * @param  array{id: int, login: string}|null  $github  GitHub identity to link, when the invitee signed in with GitHub
     */
    public function __invoke(Invitation $invitation, string $name, ?string $password, ?array $github = null): User
    {
        if ($password === null && $github === null) {
            throw new \InvalidArgumentException('An account needs a password or a GitHub identity.');
        }

        return DB::transaction(function () use ($invitation, $name, $password, $github): User {
            // Lock the row so a double-submit cannot accept the same invitation twice.
            $fresh = Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isPending()) {
                throw new InvitationInvalid;
            }

            if (User::withTrashed()->where('email', $fresh->email)->exists()) {
                throw new UserAlreadyExists;
            }

            $user = new User(['name' => $name, 'email' => $fresh->email] + ($password !== null ? ['password' => $password] : []));
            $user->forceFill([
                'is_admin' => $fresh->is_admin,
                'email_verified_at' => now(),
                'github_id' => $github['id'] ?? null,
                'github_login' => $github['login'] ?? null,
            ])->save();

            $team = $fresh->team;
            if ($team !== null && $fresh->role !== null) {
                $team->addMember($user, $fresh->role);
            }

            $fresh->forceFill(['accepted_at' => now()])->save();

            Audit::record('user.joined', $user, ['invited_by' => $fresh->invited_by] + ($github !== null ? ['method' => 'github', 'github_login' => $github['login']] : []), $user);

            return $user;
        });
    }
}
