<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Metrics\Actions\RollupMetrics;
use App\Domain\Metrics\Models\MetricSample;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:rollup-metrics {--backfill-hours=0 : Also recompute the hour samples of this many past hours}')]
#[Description('Summarise the last minute of activity (and, at the top of the hour, the last hour) for the Insights page')]
final class RollupMetricsCommand extends Command
{
    public function handle(RollupMetrics $rollup): int
    {
        $now = now();
        // The minute that just ended, plus the running one so the page is never a minute behind.
        $written = $rollup($now->copy()->subMinute(), MetricSample::MINUTE) + $rollup($now, MetricSample::MINUTE);
        // The current hour is refreshed every minute; the previous one is settled for good just after it ends.
        $written += $rollup($now, MetricSample::HOUR);
        if ($now->minute < 2) {
            $written += $rollup($now->copy()->subHour(), MetricSample::HOUR);
            $rollup->prune();
        }
        for ($h = 1; $h <= (int) $this->option('backfill-hours'); $h++) {
            $written += $rollup($now->copy()->subHours($h), MetricSample::HOUR);
        }
        $this->info("{$written} sample(s) written.");

        return self::SUCCESS;
    }
}
