<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class DeliveryInProgress extends JoggerException
{
    public function errorCode(): string
    {
        return 'DELIVERY_IN_PROGRESS';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    protected function defaultMessage(): string
    {
        return 'This delivery is being processed right now. Try again in a moment.';
    }
}
