<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class InvitationInvalid extends JoggerException
{
    public function errorCode(): string
    {
        return 'INVITATION_INVALID';
    }

    public function httpStatus(): int
    {
        return 410;
    }

    protected function defaultMessage(): string
    {
        return 'This invitation is no longer valid.';
    }
}
