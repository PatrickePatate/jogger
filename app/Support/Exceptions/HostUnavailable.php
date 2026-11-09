<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class HostUnavailable extends JoggerException
{
    public function errorCode(): string
    {
        return 'HOST_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 503;
    }

    protected function defaultMessage(): string
    {
        return 'No host is available to run this.';
    }
}
