<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Models\PersonalAccessToken;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates `Authorization: Bearer jog_…` requests and acts as the token's owner. */
final class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? PersonalAccessToken::findUsable($plain) : null;
        $user = $token?->user; // soft-deleted owners resolve to null

        if ($token === null || $user === null) {
            throw new AuthenticationException;
        }

        $token->touchLastUsed();
        $request->attributes->set('access_token', $token);
        Auth::setUser($user);

        return $next($request);
    }
}
