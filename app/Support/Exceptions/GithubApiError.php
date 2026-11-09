<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

/** GitHub refused or could not serve a request (status 0 = unreachable). */
final class GithubApiError extends JoggerException
{
    public function __construct(string $message, public readonly int $githubStatus = 0)
    {
        parent::__construct($message, ['github_status' => $githubStatus]);
    }

    public function isNotFound(): bool
    {
        return $this->githubStatus === 404;
    }

    public function errorCode(): string
    {
        return 'GITHUB_API_ERROR';
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
