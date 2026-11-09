<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class InvalidEnrollmentToken extends JoggerException
{
    public function errorCode(): string
    {
        return 'INVALID_ENROLLMENT_TOKEN';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    protected function defaultMessage(): string
    {
        return 'This enrollment token is invalid, expired or already used.';
    }
}
