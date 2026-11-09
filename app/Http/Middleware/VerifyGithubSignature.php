<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Github\Models\GithubApp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a webhook delivery: size cap, then the HMAC-SHA256 of the raw body (X-Hub-Signature-256)
 * computed with the GitHub App's webhook secret. Every refusal is the same opaque 401.
 */
final class VerifyGithubSignature
{
    /** GitHub caps webhook payloads at 25 MB; we refuse anything larger before hashing it. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->header('Content-Length', '0') > self::MAX_BYTES) {
            abort(413, 'Webhook payload is too large.');
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BYTES) {
            abort(413, 'Webhook payload is too large.');
        }

        $app = $this->app($request);
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($app === null || ! str_starts_with($signature, 'sha256=')
            || ! hash_equals('sha256='.hash_hmac('sha256', $body, $app->webhook_secret), $signature)) {
            Log::warning('GitHub webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'delivery' => $request->header('X-GitHub-Delivery'),
                'event' => $request->header('X-GitHub-Event'),
                'has_signature' => $signature !== '',
                'known_app' => $app !== null,
            ]);

            abort(401, 'Invalid webhook signature.');
        }

        $request->attributes->set('github_app', $app);

        return $next($request);
    }

    /**
     * For GitHub App webhooks X-GitHub-Hook-Installation-Target-ID is the app's id. If it is missing and
     * exactly one app is connected, that one is used; the signature still has to match its secret.
     */
    private function app(Request $request): ?GithubApp
    {
        $targetId = $request->header('X-GitHub-Hook-Installation-Target-ID');

        if ($targetId !== null) {
            // Present: it must be a plain app id (a malformed header is never silently ignored).
            return ctype_digit($targetId) ? GithubApp::query()->where('app_id', (int) $targetId)->first() : null;
        }

        $apps = GithubApp::query()->limit(2)->get();

        return $apps->count() === 1 ? $apps->first() : null;
    }
}
