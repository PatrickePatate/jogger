<?php

declare(strict_types=1);

namespace App\Domain\Runners\Enums;

enum RunnerState: string
{
    case Provisioning = 'provisioning'; // slot reserved, container being started
    case Idle = 'idle';                 // container up, waiting for a job
    case Busy = 'busy';                 // running a job
    case Terminating = 'terminating';   // stop requested, waiting for the container to go
    case Gone = 'gone';                 // ended normally
    case Failed = 'failed';             // ended abnormally, see exit_reason

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Provisioning => [self::Idle, self::Busy, self::Failed, self::Terminating],
            self::Idle => [self::Busy, self::Terminating, self::Failed],
            self::Busy => [self::Terminating, self::Failed],
            self::Terminating => [self::Gone, self::Failed],
            self::Gone, self::Failed => [],
        };
    }

    public function canBecome(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /**
     * States from which this one may be entered.
     *
     * @return list<self>
     */
    public function previous(): array
    {
        return array_values(array_filter(self::cases(), fn (self $from) => $from->canBecome($this)));
    }

    /** Counts against the pool's max_runners. */
    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Gone || $this === self::Failed;
    }

    /** @return list<self> */
    public static function live(): array
    {
        return [self::Provisioning, self::Idle, self::Busy];
    }

    /**
     * Everything that may still have a container on a host.
     *
     * @return list<self>
     */
    public static function present(): array
    {
        return [self::Provisioning, self::Idle, self::Busy, self::Terminating];
    }

    /**
     * @param  list<self>  $states
     * @return list<string>
     */
    public static function values(array $states): array
    {
        return array_map(fn (self $s) => $s->value, $states);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
