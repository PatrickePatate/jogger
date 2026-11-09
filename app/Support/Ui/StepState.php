<?php

declare(strict_types=1);

namespace App\Support\Ui;

enum StepState: string
{
    case Done = 'done';
    case Active = 'active';
    case Pending = 'pending';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /** Screen-reader text; state is never conveyed by color alone. */
    public function label(): string
    {
        return match ($this) {
            self::Done => 'Done',
            self::Active => 'In progress',
            self::Pending => 'Pending',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
