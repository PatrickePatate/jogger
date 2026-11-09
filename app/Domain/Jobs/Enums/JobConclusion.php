<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Enums;

enum JobConclusion: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
    case TimedOut = 'timed_out';
    case ActionRequired = 'action_required';
    case Neutral = 'neutral';

    /** An unrecognised conclusion becomes "neutral" (neither success nor failure) rather than breaking the mirror. */
    public static function fromGithub(?string $conclusion): ?self
    {
        return $conclusion === null || $conclusion === '' ? null : (self::tryFrom($conclusion) ?? self::Neutral);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::Failure, self::TimedOut], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Succeeded',
            self::Failure => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Skipped => 'Skipped',
            self::TimedOut => 'Timed out',
            self::ActionRequired => 'Action required',
            self::Neutral => 'Neutral',
        };
    }
}
