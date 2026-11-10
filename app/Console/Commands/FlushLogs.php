<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Jobs\Logs\LogIngestor;
use App\Domain\Jobs\Models\JobLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:flush-logs {--idle=15 : Minutes of silence after which an open log is closed}')]
#[Description('Close logs whose runner went silent without an end marker (expired logs are removed by jogger:prune)')]
final class FlushLogs extends Command
{
    public function handle(LogIngestor $ingestor): int
    {
        $closed = 0;
        JobLog::query()
            ->whereNull('finalized_at')
            ->where(fn ($q) => $q->whereNull('last_chunk_at')->orWhere('last_chunk_at', '<', now()->subMinutes(max(1, (int) $this->option('idle')))))
            ->eachById(function (JobLog $log) use ($ingestor, &$closed) {
                $ingestor->finalize($log);
                $closed++;
            }, 100, 'runner_id');

        $this->info("Closed {$closed} idle logs.");

        return self::SUCCESS;
    }
}
