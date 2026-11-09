<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class InvalidTransition extends JoggerException
{
    public static function between(string $subject, string $from, string $to): self
    {
        return new self("Cannot move {$subject} from {$from} to {$to}.", ['from' => $from, 'to' => $to]);
    }

    public function errorCode(): string
    {
        return 'INVALID_TRANSITION';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
