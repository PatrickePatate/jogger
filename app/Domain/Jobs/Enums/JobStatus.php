<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Enums;

/**
 * A job only ever moves forward: waiting → queued → in_progress → completed. Webhooks can arrive late or out of
 * order, so "newer" is decided by rank, never by arrival time: an older state is simply ignored.
 */
enum JobStatus: string
{
    case Waiting = 'waiting';       // held back (e.g. awaiting an environment approval)
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /** GitHub's `status` for a job. Statuses we do not model (pending, requested) are treated as queued. */
    public static function fromGithub(string $status): self
    {
        return self::tryFrom($status) ?? self::Queued;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Waiting => 0,
            self::Queued => 1,
            self::InProgress => 2,
            self::Completed => 3,
        };
    }

    /** Whether moving from this status to $next is a real step forward. */
    public function canAdvanceTo(self $next): bool
    {
        return $next->rank() > $this->rank();
    }

    /**
     * Statuses a job may be in right before $this: used by the optimistic UPDATE ... WHERE status IN (...).
     *
     * @return array<int, string>
     */
    public function precedingStatuses(): array
    {
        return array_map(
            fn (self $s) => $s->value,
            array_values(array_filter(self::cases(), fn (self $s) => $s->rank() < $this->rank())),
        );
    }

    public function isFinal(): bool
    {
        return $this === self::Completed;
    }

    /** Jobs that still need (or hold) a runner. */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Queued => 'Queued',
            self::InProgress => 'Running',
            self::Completed => 'Completed',
        };
    }
}
