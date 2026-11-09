<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Setup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The wizard is only reachable on a fresh install; afterwards visitors go to the app. */
final class RedirectIfSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Setup::isComplete()) {
            return redirect()->route('overview');
        }

        return $next($request);
    }
}
