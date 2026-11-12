<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Exceptions;

use RuntimeException;

/** Any failure talking to a cloud provider. `reason` tells the scaler how to react. */
final class CloudException extends RuntimeException
{
    /** @param  'quota'|'credentials'|'api'|'no_provider'  $reason */
    private function __construct(string $message, public readonly string $reason, int $code = 0)
    {
        parent::__construct($message, $code);
    }

    public static function quotaReached(string $message): self
    {
        return new self($message, 'quota');
    }

    public static function invalidCredentials(string $message): self
    {
        return new self($message, 'credentials');
    }

    public static function api(string $message, int $status): self
    {
        return new self($message, 'api', $status);
    }

    /** The host's template (and with it the provider) was deleted, so there is no account to ask. */
    public static function noProvider(string $message): self
    {
        return new self($message, 'no_provider');
    }
}
