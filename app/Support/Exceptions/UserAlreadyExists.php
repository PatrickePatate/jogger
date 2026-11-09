<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class UserAlreadyExists extends JoggerException
{
    public function errorCode(): string
    {
        return 'USER_ALREADY_EXISTS';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    protected function defaultMessage(): string
    {
        return 'A user with that email already exists.';
    }
}
