<?php

declare(strict_types=1);

namespace App\Domain\Github\Data;

use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * Response of POST .../actions/runners/generate-jitconfig: the single-use credential handed to a
 * runner container. The encoded config is a secret, so it is redacted from debug output.
 */
final class JitConfig extends Data
{
    /**
     * @param  array<int, string>  $labels
     */
    public function __construct(
        public int $runnerId,
        public string $name,
        #[SensitiveParameter]
        public string $encodedJitConfig,
        public array $labels = [],
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        /** @var array<string, mixed> $runner */
        $runner = $properties['runner'] ?? [];

        // Missing keys are left out (not set to null) so required fields fail with a clear
        // "missing property" error and optional ones fall back to their defaults.
        return array_filter([
            'runnerId' => $runner['id'] ?? $properties['runnerId'] ?? null,
            'name' => $runner['name'] ?? $properties['name'] ?? null,
            'encodedJitConfig' => $properties['encoded_jit_config'] ?? $properties['encodedJitConfig'] ?? null,
            'labels' => array_values(array_map(
                fn ($l) => is_array($l) ? (string) ($l['name'] ?? '') : (string) $l,
                $runner['labels'] ?? $properties['labels'] ?? [],
            )),
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['runnerId' => $this->runnerId, 'name' => $this->name, 'encodedJitConfig' => '[redacted]', 'labels' => $this->labels];
    }
}
