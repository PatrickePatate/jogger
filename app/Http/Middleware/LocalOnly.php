<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Dev-only routes (e.g. the UI kit showcase) 404 outside the local environment. */
final class LocalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->isLocal(), 404);

        return $next($request);
    }
}
