<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Setup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the first admin exists, every web route sends visitors to the setup wizard. */
final class EnsureSetupComplete
{
    /** Paths that must keep working on a fresh install. */
    private const EXCEPT = ['setup', 'setup/*', 'livewire*', 'broadcasting/*', 'webhooks/*', '_ui', '_ui/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(...self::EXCEPT) && ! Setup::isComplete()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
