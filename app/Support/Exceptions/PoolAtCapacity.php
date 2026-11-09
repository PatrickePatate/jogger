<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class PoolAtCapacity extends JoggerException
{
    public function errorCode(): string
    {
        return 'POOL_LIMIT_EXCEEDED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    protected function defaultMessage(): string
    {
        return 'Pool has reached its maximum number of runners.';
    }
}
