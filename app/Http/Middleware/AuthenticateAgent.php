<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Hosts\Models\Host;
use App\Support\Exceptions\InvalidAgentCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates `Authorization: Bearer jha_…` as a host. Looked up on every request, so revocation is immediate. */
final class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $host = $plain ? Host::findByCredential($plain) : null;

        if ($host === null) {
            throw new InvalidAgentCredential;
        }

        $request->attributes->set('host', $host);

        return $next($request);
    }
}
