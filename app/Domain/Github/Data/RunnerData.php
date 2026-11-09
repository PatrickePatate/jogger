<?php

declare(strict_types=1);

namespace App\Domain\Github\Data;

use Spatie\LaravelData\Data;

/** A self-hosted runner as listed by GitHub. */
final class RunnerData extends Data
{
    /**
     * @param  array<int, string>  $labels
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public bool $busy = false,
        public ?string $os = null,
        public array $labels = [],
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        return array_filter([
            'id' => $properties['id'] ?? null,
            'name' => $properties['name'] ?? null,
            'status' => $properties['status'] ?? null,
            'busy' => $properties['busy'] ?? false,
            'os' => $properties['os'] ?? null,
            'labels' => array_values(array_map(
                fn ($l) => is_array($l) ? (string) ($l['name'] ?? '') : (string) $l,
                $properties['labels'] ?? [],
            )),
        ], static fn ($value) => $value !== null);
    }
}
