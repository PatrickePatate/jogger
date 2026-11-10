<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook and agent endpoints are called by machines. A request a browser made from another site announces
 * itself (`Origin`, `Sec-Fetch-Site`) and is refused before any credential is looked at.
 */
final class RejectBrowserRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');

        abort_if(($site !== null && $site !== 'none' && $site !== 'same-origin')
            || ($origin !== null && $origin !== $request->getSchemeAndHttpHost()), 403, 'Cross-origin requests are not accepted here.');

        return $next($request);
    }
}
