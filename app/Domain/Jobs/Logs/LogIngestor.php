<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Logs;

use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Models\JobLog;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Runners\Models\Runner;
use App\Events\LogChunkReceived;
use Illuminate\Support\Facades\DB;

/** Accepts a runner's log batches in order: masks secrets, enforces the size cap, stores, and broadcasts. */
final class LogIngestor
{
    public function __construct(
        private readonly LogStore $store,
        private readonly SecretMasker $masker,
        private readonly SecretSource $secrets,
    ) {}

    /**
     * @return array{accepted: bool, last_seq: int, truncated: bool}|null null when the runner's log belongs to another host
     */
    public function ingest(Host $host, string $runnerId, int $seq, string $data, bool $eof = false): ?array
    {
        [$result, $broadcast] = DB::transaction(function () use ($host, $runnerId, $seq, $data, $eof) {
            JobLog::query()->insertOrIgnore(['runner_id' => $runnerId, 'host_id' => $host->id]);
            /** @var JobLog $log */
            $log = JobLog::query()->whereKey($runnerId)->lockForUpdate()->firstOrFail();

            // Only the host the runner was placed on may write its log.
            $placedOn = Runner::query()->whereKey($runnerId)->value('host_id');
            if ($log->host_id !== $host->id || ($placedOn !== null && $placedOn !== $host->id)) {
                return [null, null];
            }
            // Already have it (the agent retried), or the log is closed: success, nothing to do.
            if ($seq <= $log->last_seq || $log->finalized_at !== null) {
                return [$this->result($log, false), null];
            }

            // The agent sends in order and discards what we refuse, so a jump means lost batches, not reordering.
            if ($seq > $log->last_seq + 1 && $log->last_seq > 0) {
                $missing = $seq - $log->last_seq - 1;
                $data = "[jogger: {$missing} log batch(es) missing]\n".$data;
            }

            $data = $this->masker->mask($data, $this->secrets->forRunner($runnerId));
            $data = $this->cap($log, $data);
            $this->store->append($runnerId, $data);

            $log->size_bytes += strlen($data);
            $log->line_count += substr_count($data, "\n");
            $log->last_seq = $seq;
            $log->last_chunk_at = now();
            $log->job_id ??= WorkflowJob::query()->where('runner_id', $runnerId)->value('id');
            $log->save();

            if ($eof) {
                $this->finalize($log);
            }

            return [$this->result($log, true), $log->job_id !== null && $data !== '' ? [$log->job_id, $data] : null];
        });

        if ($broadcast !== null) {
            foreach (mb_str_split($broadcast[1], LogChunkReceived::MAX_BYTES / 4) as $part => $slice) {
                LogChunkReceived::dispatch($broadcast[0], $seq, $part, $slice);
            }
        }

        return $result;
    }

    /** Closes the log: gzip it and start its retention clock. Safe to call twice. */
    public function finalize(JobLog $log): void
    {
        if ($log->finalized_at !== null) {
            return;
        }
        $log->storage_path = $this->store->archive($log->runner_id);
        $log->finalized_at = now();
        $log->expires_at = now()->addDays(max(1, (int) config('jogger.log_retention_days')));
        $log->job_id ??= WorkflowJob::query()->where('runner_id', $log->runner_id)->value('id');
        $log->save();
    }

    /** Cuts the data at the per-job limit, ending the log with a marker line. Everything after is discarded. */
    private function cap(JobLog $log, string $data): string
    {
        if ($log->truncated) {
            return '';
        }
        $max = (int) config('jogger.log_max_bytes_per_job');
        $room = $max - $log->size_bytes;
        if (strlen($data) <= $room) {
            return $data;
        }
        $log->truncated = true;
        $kept = mb_strcut($data, 0, max(0, $room), 'UTF-8');

        return $kept.($kept === '' || str_ends_with($kept, "\n") ? '' : "\n")."[jogger: log truncated at {$max} bytes]\n";
    }

    /**
     * @return array{accepted: bool, last_seq: int, truncated: bool}
     */
    private function result(JobLog $log, bool $accepted): array
    {
        return ['accepted' => $accepted, 'last_seq' => $log->last_seq, 'truncated' => $log->truncated];
    }
}
