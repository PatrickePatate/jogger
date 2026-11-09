<?php

declare(strict_types=1);

namespace App\Domain\Github\Gateway;

use App\Domain\Github\Models\GithubApp;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Builds GitHub HTTP clients (headers, timeout, 5xx retry with jittered backoff) and maps failures to exceptions. */
final class GithubHttp
{
    /** A client for endpoints that take no credentials (the manifest code exchange). */
    public function unauthenticated(string $apiBaseUrl): PendingRequest
    {
        return $this->configure(Http::baseUrl(rtrim($apiBaseUrl, '/')));
    }

    public function pending(GithubApp $app, string $bearerToken): PendingRequest
    {
        return $this->configure(Http::baseUrl(rtrim($app->api_base_url, '/'))->withToken($bearerToken));
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        $retries = max(0, (int) config('jogger.github.retries'));
        $baseMs = max(0, (int) config('jogger.github.retry_base_ms'));

        return $request
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => (string) config('jogger.github.api_version'),
                'User-Agent' => 'Jogger/'.config('app.name'),
            ])
            ->timeout((int) config('jogger.github.timeout'))
            ->retry(
                $retries + 1, // total attempts = first try + retries
                fn (int $attempt) => $baseMs === 0 ? 0 : (int) ($baseMs * (2 ** ($attempt - 1)) + random_int(0, (int) ($baseMs / 2))),
                // Only transient failures are retried: connection errors and 5xx. Never 4xx or rate limits.
                fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
    }

    /**
     * Turns a non-successful response into the right exception.
     *
     * @throws GithubRateLimited
     * @throws GithubApiError
     */
    public function failure(Response $response): never
    {
        $retryAfter = $this->rateLimitSeconds($response);

        if ($retryAfter !== null) {
            throw new GithubRateLimited('GitHub API rate limit reached.', $retryAfter);
        }

        $status = $response->status();
        $detail = Str::limit((string) data_get($response->json(), 'message', ''), 200);

        throw new GithubApiError(match (true) {
            $status === 401 => 'GitHub rejected our credentials (check the GitHub App key and the server clock).',
            $status === 403 => 'GitHub denied access: the GitHub App lacks a permission for this action.'.($detail !== '' ? " ({$detail})" : ''),
            $status === 404 => 'GitHub resource not found (or the app cannot see it).',
            $status >= 500 => "GitHub is unavailable (HTTP {$status}).",
            default => "GitHub rejected the request (HTTP {$status})".($detail !== '' ? ": {$detail}" : '.'),
        }, $status);
    }

    /**
     * Seconds to wait if this response is a rate-limit refusal, null otherwise. Follows GitHub's guidance:
     * `retry-after` first, then `x-ratelimit-reset` when the bucket is empty, otherwise one minute.
     */
    public function rateLimitSeconds(Response $response): ?int
    {
        if (! in_array($response->status(), [403, 429], true)) {
            return null;
        }

        $retryAfter = $response->header('retry-after');
        $remaining = $response->header('x-ratelimit-remaining');
        $reset = $response->header('x-ratelimit-reset');
        $message = strtolower((string) data_get($response->json(), 'message', ''));
        $secondary = str_contains($message, 'secondary rate limit') || str_contains($message, 'abuse detection');

        if (is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        if ($remaining === '0' && is_numeric($reset)) {
            return max(1, (int) $reset - CarbonImmutable::now()->timestamp);
        }

        return $response->status() === 429 || $secondary ? 60 : null;
    }

    public function unreachable(ConnectionException $e): GithubApiError
    {
        return new GithubApiError('Could not reach GitHub: '.Str::limit($e->getMessage(), 160));
    }
}
