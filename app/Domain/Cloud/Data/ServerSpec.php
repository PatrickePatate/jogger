<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Data;

/** What to ask a provider for. */
final readonly class ServerSpec
{
    /**
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $options  provider-specific fields from the template
     */
    public function __construct(
        public string $name,
        public string $serverType,
        public string $location,
        public string $image,
        public string $userData,
        public bool $ipv4,
        public array $labels,
        public array $options,
    ) {}
}
