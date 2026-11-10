<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defences for every response: a nonce-based Content-Security-Policy, no framing, no MIME sniffing,
 * and HSTS when the request came over HTTPS.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Vite tags, Livewire's script and our inline scripts all carry this nonce.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $github = rtrim((string) config('jogger.github.web_url'), '/');
        $policy = implode('; ', [
            "default-src 'self'",
            // 'unsafe-eval' is what Alpine's expression evaluator needs; inline scripts still require the nonce.
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https://avatars.githubusercontent.com",
            "font-src 'self' data:",
            "connect-src 'self' ws: wss:",
            "form-action 'self' {$github}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);

        $response->headers->set(config('jogger.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $policy);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
