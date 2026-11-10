<?php

declare(strict_types=1);

namespace App\Domain\Github\Support;

use App\Support\Exceptions\GithubRateLimited;
use Closure;

/**
 * Queue job middleware. The job says which installation it works for (`githubInstallationId()`); while that lane
 * is paused the job goes back on the queue until the reset time, and a rate-limit error from the job pauses the
 * lane for everyone behind it.
 */
final class RateLimitedByGithub
{
    public function __construct(private readonly GithubLane $lane) {}

    /**
     * @param  object  $job  a queued job using InteractsWithQueue and exposing githubInstallationId(): ?string
     */
    public function handle(object $job, Closure $next): void
    {
        $installationId = method_exists($job, 'githubInstallationId') ? $job->githubInstallationId() : null;
        if ($installationId === null) {
            $next($job);

            return;
        }

        $wait = $this->lane->pausedFor($installationId);
        if ($wait > 0) {
            $this->waiting($job, $wait);

            return;
        }

        try {
            $next($job);
        } catch (GithubRateLimited $e) {
            $this->lane->pause($installationId, $e->retryAfterSeconds);
            $this->waiting($job, $this->lane->pausedFor($installationId));
        }
    }

    private function waiting(object $job, int $seconds): void
    {
        if (method_exists($job, 'onGithubRateLimit')) {
            $job->onGithubRateLimit($seconds);
        }
        // A second of slack so the job does not wake up a moment before the lane reopens.
        $job->release($seconds + 1);
    }
}
