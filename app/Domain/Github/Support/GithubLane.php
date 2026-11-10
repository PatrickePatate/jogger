<?php

declare(strict_types=1);

namespace App\Domain\Github\Support;

use Illuminate\Support\Facades\Cache;

/**
 * One "lane" of GitHub API work per installation (rate limits are per installation). When GitHub says to slow
 * down, the lane is paused until the reset time and everything queued for it waits instead of hammering the API.
 */
final class GithubLane
{
    public const DEFAULT_PAUSE_SECONDS = 60;

    public function pause(string $installationId, ?int $seconds): void
    {
        $seconds = max(1, $seconds ?? self::DEFAULT_PAUSE_SECONDS);
        $until = now()->addSeconds($seconds)->getTimestamp();

        // Never shorten a pause that is already longer.
        if ($until > (int) Cache::get($this->key($installationId), 0)) {
            Cache::put($this->key($installationId), $until, $seconds);
        }
    }

    /** Seconds until the lane reopens; 0 when it is open. */
    public function pausedFor(string $installationId): int
    {
        return max(0, (int) Cache::get($this->key($installationId), 0) - now()->getTimestamp());
    }

    public function resume(string $installationId): void
    {
        Cache::forget($this->key($installationId));
    }

    private function key(string $installationId): string
    {
        return "github:lane:{$installationId}:paused_until";
    }
}
