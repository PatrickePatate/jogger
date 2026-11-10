<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Jobs\Logs\LogStore;
use App\Domain\Jobs\Models\JobLog;
use App\Domain\Jobs\Models\WorkflowJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reading a job's log: paged scrollback for the viewer, and the whole text as a download. Used by web and API. */
final class JobLogController extends Controller
{
    /** Most a single scrollback request returns. */
    public const MAX_SLICE = 1_048_576;

    public function __construct(private readonly LogStore $store) {}

    /**
     * A window of the log by byte offset, plus where the live stream stands. The viewer loads this, then applies
     * only broadcast chunks with `seq` greater than `last_seq`, so a reload neither repeats nor loses lines.
     * `offset=-N` means the last N bytes.
     */
    public function scrollback(Request $request, string $job): JsonResponse
    {
        $log = $this->log($request, $job);
        if ($log === null) {
            return response()->json(['data' => '', 'offset' => 0, 'next_offset' => 0, 'size' => 0, 'last_seq' => 0, 'finalized' => false, 'truncated' => false]);
        }

        // Size and seq come from one row read: the text returned is exactly what those batches wrote.
        $size = $log->size_bytes;
        $limit = min(self::MAX_SLICE, max(1, $request->integer('limit', self::MAX_SLICE)));
        $offset = $request->integer('offset', 0);
        $offset = $offset < 0 ? max(0, $size + $offset) : min($offset, $size);

        // A window that starts mid-line (or mid-character) is moved to the next line break so it renders cleanly.
        if ($offset > 0 && $this->store->slice($log, $offset - 1, 1, $size) !== "\n") {
            $skip = strpos($this->store->slice($log, $offset, self::MAX_SLICE, $size), "\n");
            $offset = $skip === false ? $size : $offset + $skip + 1;
        }
        $data = $this->store->slice($log, $offset, $limit, $size);

        return response()->json([
            'data' => mb_scrub($data, 'UTF-8'),
            'offset' => $offset,
            'next_offset' => $offset + strlen($data),
            'size' => $size,
            'last_seq' => $log->last_seq,
            'finalized' => $log->finalized_at !== null,
            'truncated' => $log->truncated,
        ]);
    }

    /** The whole log. `?format=gz` returns the stored gzip archive when the log is finished. */
    public function download(Request $request, string $job): StreamedResponse|BinaryFileResponse
    {
        $log = $this->log($request, $job);
        $name = "jogger-job-{$job}.log";

        if ($log !== null && $request->query('format') === 'gz' && ($archive = $this->store->archivePath($log)) !== null) {
            return response()->download($archive, "{$name}.gz", ['Content-Type' => 'application/gzip']);
        }

        $store = $this->store;

        return response()->streamDownload(function () use ($log, $store) {
            if ($log === null) {
                return;
            }
            $size = $log->size_bytes;
            for ($offset = 0; $offset < $size; $offset += self::MAX_SLICE) {
                echo $store->slice($log, $offset, self::MAX_SLICE, $size);
            }
        }, $name, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The job must be visible to the caller (404 otherwise, like a job that does not exist). */
    private function log(Request $request, string $jobId): ?JobLog
    {
        abort_unless(Str::isUuid($jobId), 404);
        $job = WorkflowJob::query()->visibleTo($request->user())->findOrFail($jobId);

        return JobLog::query()->where('job_id', $job->id)->first();
    }
}
