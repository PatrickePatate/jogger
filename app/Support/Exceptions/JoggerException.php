<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

abstract class JoggerException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = '', protected array $details = [])
    {
        parent::__construct($message !== '' ? $message : $this->defaultMessage());
    }

    /** Machine-readable error code, e.g. POOL_LIMIT_EXCEEDED. */
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [];
    }

    protected function defaultMessage(): string
    {
        return 'Request could not be completed.';
    }
}
