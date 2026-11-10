<?php

declare(strict_types=1);

namespace App\Domain\Pools\Data;

use App\Domain\Pools\Models\RunnerPool;

/** The outcome of matching a job to a pool: the pool, or the reason there is none. */
final readonly class PoolMatch
{
    public const NO_MATCHING_POOL = 'NO_MATCHING_POOL';

    public const POOL_PAUSED = 'POOL_PAUSED';

    public const NO_CAPACITY = 'NO_CAPACITY';

    /**
     * @param  list<string>  $labels  the labels the job asked for
     */
    public function __construct(
        public ?RunnerPool $pool,
        public ?string $code = null,
        public array $labels = [],
    ) {}

    public function matched(): bool
    {
        return $this->pool !== null;
    }

    /** Human-readable reason, short enough for workflow_jobs.unmatched_reason: "CODE: sentence". */
    public function reason(): ?string
    {
        $labels = implode(', ', $this->labels);

        return match ($this->code) {
            null => null,
            self::POOL_PAUSED => self::describe(self::POOL_PAUSED, "the pool for [{$labels}] is paused"),
            default => self::describe(self::NO_MATCHING_POOL, "no pool has all of these labels: {$labels}"),
        };
    }

    public static function describe(string $code, string $sentence): string
    {
        return mb_strimwidth("{$code}: {$sentence}", 0, 200, '…');
    }
}
