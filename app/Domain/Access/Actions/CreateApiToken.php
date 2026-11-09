<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\Audit;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

final class CreateApiToken
{
    /**
     * Returns the plain token (shown to the user once) and its stored record.
     *
     * @return array{0: string, 1: PersonalAccessToken}
     *
     * @throws AuthorizationException when the user could not hold that role themselves
     */
    public function __invoke(User $user, string $name, Role $role, ?CarbonInterface $expiresAt = null): array
    {
        $ceiling = $user->maxRole();

        if ($role !== Role::Viewer && ($ceiling === null || ! $ceiling->atLeast($role))) {
            throw new AuthorizationException('You cannot create a token with more access than you have.');
        }

        $plain = PersonalAccessToken::PREFIX.Str::random(40);

        $token = $user->accessTokens()->create([
            'name' => $name,
            'token_hash' => PersonalAccessToken::hash($plain),
            'role' => $role,
            'expires_at' => $expiresAt,
        ]);

        Audit::record('token.created', $token, ['name' => $name, 'role' => $role->value, 'expires_at' => $expiresAt?->toIso8601String()], $user);

        return [$plain, $token];
    }
}
