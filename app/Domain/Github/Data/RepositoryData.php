<?php

declare(strict_types=1);

namespace App\Domain\Github\Data;

use Spatie\LaravelData\Data;

/** A repository as GitHub describes it in webhooks and API responses (extra fields are ignored). */
final class RepositoryData extends Data
{
    public function __construct(
        public int $id,
        public string $fullName,
        public bool $private,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        // Missing keys are left out (not set to null) so required fields fail with a clear
        // "missing property" error and optional ones fall back to their defaults.
        return array_filter([
            'id' => $properties['id'] ?? null,
            'fullName' => $properties['full_name'] ?? $properties['fullName'] ?? null,
            'private' => $properties['private'] ?? true, // unknown visibility is treated as private
        ], static fn ($value) => $value !== null);
    }

    public function owner(): string
    {
        return explode('/', $this->fullName, 2)[0];
    }
}
