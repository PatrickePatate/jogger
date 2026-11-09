<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class InvalidAgentCredential extends JoggerException
{
    public function errorCode(): string
    {
        return 'INVALID_AGENT_CREDENTIAL';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    protected function defaultMessage(): string
    {
        return 'The agent credential is missing, unknown or revoked.';
    }
}
