<?php

declare(strict_types=1);

namespace App\Domain\Github\Gateway;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers each installation's rate-limit state (from response headers) and any enforced pause,
 * so queue jobs (Task 38) and the UI can back off without asking GitHub again.
 */
final class RateLimitTracker
{
    public function record(int $installationId, Response $response): void
    {
        $remaining = $response->header('x-ratelimit-remaining');
        $reset = $response->header('x-ratelimit-reset');

        if (is_numeric($remaining) && is_numeric($reset)) {
            Cache::put($this->stateKey($installationId), ['remaining' => (int) $remaining, 'reset' => (int) $reset], 3600);
        }
    }

    public function pause(int $installationId, int $seconds): void
    {
        Cache::put($this->pauseKey($installationId), CarbonImmutable::now()->addSeconds($seconds)->timestamp, $seconds);
    }

    /** When requests may resume, or null if the installation is not paused. */
    public function pausedUntil(int $installationId): ?CarbonImmutable
    {
        $until = Cache::get($this->pauseKey($installationId));

        return is_int($until) && $until > CarbonImmutable::now()->timestamp ? CarbonImmutable::createFromTimestampUTC($until) : null;
    }

    /**
     * @return array{remaining: int, reset: int}|null
     */
    public function state(int $installationId): ?array
    {
        $state = Cache::get($this->stateKey($installationId));

        return is_array($state) ? $state : null;
    }

    private function stateKey(int $installationId): string
    {
        return "github.rate.{$installationId}";
    }

    private function pauseKey(int $installationId): string
    {
        return "github.paused.{$installationId}";
    }
}
