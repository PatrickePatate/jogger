<?php

declare(strict_types=1);

namespace App\Domain\Github\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/** The "core" REST rate limit bucket of an installation. */
final class RateLimitData extends Data
{
    public function __construct(
        public int $limit,
        public int $remaining,
        public int $used,
        public CarbonImmutable $reset,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        /** @var array<string, mixed> $core */
        $core = $properties['resources']['core'] ?? $properties['rate'] ?? $properties;

        return array_filter([
            'limit' => $core['limit'] ?? null,
            'remaining' => $core['remaining'] ?? null,
            'used' => $core['used'] ?? (isset($core['limit'], $core['remaining']) ? $core['limit'] - $core['remaining'] : null),
            'reset' => isset($core['reset']) ? CarbonImmutable::createFromTimestampUTC((int) $core['reset']) : null,
        ], static fn ($value) => $value !== null);
    }
}
