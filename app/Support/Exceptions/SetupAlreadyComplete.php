<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class SetupAlreadyComplete extends JoggerException
{
    public function errorCode(): string
    {
        return 'SETUP_ALREADY_COMPLETE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    protected function defaultMessage(): string
    {
        return 'Jogger is already set up.';
    }
}
