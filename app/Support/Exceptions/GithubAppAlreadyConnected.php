<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class GithubAppAlreadyConnected extends JoggerException
{
    public function errorCode(): string
    {
        return 'GITHUB_APP_ALREADY_CONNECTED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    protected function defaultMessage(): string
    {
        return 'A GitHub App is already connected to this Jogger instance.';
    }
}
