<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class GithubRateLimited extends JoggerException
{
    public function __construct(string $message = '', public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message, $retryAfterSeconds !== null ? ['retry_after' => $retryAfterSeconds] : []);
    }

    public function errorCode(): string
    {
        return 'GITHUB_RATE_LIMITED';
    }

    public function httpStatus(): int
    {
        return 503;
    }

    public function headers(): array
    {
        return $this->retryAfterSeconds !== null ? ['Retry-After' => (string) $this->retryAfterSeconds] : [];
    }

    protected function defaultMessage(): string
    {
        return 'GitHub API rate limit reached.';
    }
}
