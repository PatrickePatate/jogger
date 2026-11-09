<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\PersonalAccessToken;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware `api.role:operator`: the token's role must be at least the given role. */
final class RequireTokenRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        /** @var PersonalAccessToken|null $token */
        $token = $request->attributes->get('access_token');

        if ($token === null || ! $token->role->atLeast(Role::from($role))) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
