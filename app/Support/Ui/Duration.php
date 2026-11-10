<?php

declare(strict_types=1);

namespace App\Support\Ui;

use Carbon\CarbonInterface;

/** Short durations for tables: "8s", "2m 05s", "1h 12m". */
final class Duration
{
    public static function between(?CarbonInterface $from, ?CarbonInterface $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }

        return self::seconds((int) max(0, $from->diffInSeconds($to, true)));
    }

    public static function seconds(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s',
            default => intdiv($seconds, 3600).'h '.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).'m',
        };
    }
}
