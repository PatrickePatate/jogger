<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Invitation;
use App\Domain\Access\Models\Team;
use App\Mail\InvitationMail;
use App\Models\User;
use App\Support\Exceptions\UserAlreadyExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invites someone by email. Inviting an address that already has an open invitation
 * updates its terms and re-sends it (this is also how "resend" works).
 */
final class InviteUser
{
    /**
     * @throws UserAlreadyExists when the address belongs to an existing or deactivated user
     */
    public function __invoke(User $inviter, string $email, bool $administrator = false, ?Team $team = null, ?Role $role = null): Invitation
    {
        $email = Str::lower(trim($email));

        if (User::withTrashed()->where('email', $email)->exists()) {
            throw new UserAlreadyExists;
        }

        return DB::transaction(function () use ($inviter, $email, $administrator, $team, $role): Invitation {
            $invitation = Invitation::pending()->where('email', $email)->first() ?? new Invitation;

            $invitation->forceFill([
                'email' => $email,
                'is_admin' => $administrator,
                'team_id' => $team?->id,
                'role' => $team ? ($role ?? Role::Viewer) : null,
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ])->save();

            Audit::record('user.invited', $invitation, [
                'email' => $email,
                'administrator' => $administrator,
                'team' => $team?->name,
                'role' => $invitation->role?->value,
            ], $inviter);

            Mail::to($email)->send(new InvitationMail($invitation));

            return $invitation;
        });
    }
}
