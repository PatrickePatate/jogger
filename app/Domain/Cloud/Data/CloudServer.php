<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Data;

use Carbon\CarbonImmutable;

/** A server as the provider reports it. Drivers map the provider's own states onto `status`. */
final readonly class CloudServer
{
    /**
     * @param  'starting'|'running'|'stopping'|'unknown'  $status
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?string $ipv4,
        public ?string $ipv6,
        public ?CarbonImmutable $createdAt,
        public array $labels,
    ) {}
}
