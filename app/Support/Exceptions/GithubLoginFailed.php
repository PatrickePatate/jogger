<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class GithubLoginFailed extends JoggerException
{
    public function errorCode(): string
    {
        return 'GITHUB_LOGIN_FAILED';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    protected function defaultMessage(): string
    {
        return 'Signing in with GitHub failed. Please try again.';
    }
}
