<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class LastAdmin extends JoggerException
{
    public function errorCode(): string
    {
        return 'LAST_ADMIN';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    protected function defaultMessage(): string
    {
        return 'At least one administrator must remain.';
    }
}
