<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tags every request with an ID (reusing a sane inbound X-Request-Id),
 * exposes it on the response, and adds it to log context.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = (string) $request->header(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9._-]{1,64}$/', $inbound) === 1
            ? $inbound
            : 'req_'.Str::lower(Str::random(16));

        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
