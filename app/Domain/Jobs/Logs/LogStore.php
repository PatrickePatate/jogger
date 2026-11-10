<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Logs;

use App\Domain\Jobs\Models\JobLog;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** The log text on disk: a plain file while the runner is alive, gzipped once it is finished. */
final class LogStore
{
    public function livePath(string $runnerId): string
    {
        return "live/{$runnerId}.log";
    }

    public function append(string $runnerId, string $data): void
    {
        if ($data === '') {
            return;
        }
        $path = $this->disk()->path($this->livePath($runnerId));
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0750, true);
        }
        if (file_put_contents($path, $data, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Cannot write log file {$path}");
        }
    }

    /** Compresses the live file and returns the archive's path on the disk, or null when nothing was ever written. */
    public function archive(string $runnerId): ?string
    {
        $disk = $this->disk();
        $live = $this->livePath($runnerId);
        if (! $disk->exists($live)) {
            return null;
        }

        $target = 'archive/'.now()->format('Y/m')."/{$runnerId}.log.gz";
        $to = $disk->path($target);
        if (! is_dir(dirname($to))) {
            @mkdir(dirname($to), 0750, true);
        }

        $in = fopen($disk->path($live), 'rb');
        $out = gzopen($to, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot archive log of runner {$runnerId}");
        }
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 16));
        }
        fclose($in);
        gzclose($out);
        $disk->delete($live);

        return $target;
    }

    /** The whole log as text (callers page or tail it themselves). */
    public function read(JobLog $log): string
    {
        $disk = $this->disk();
        if ($log->storage_path !== null && $disk->exists($log->storage_path)) {
            return (string) gzdecode((string) $disk->get($log->storage_path));
        }
        $live = $this->livePath($log->runner_id);

        return $disk->exists($live) ? (string) $disk->get($live) : '';
    }

    /**
     * Up to $length bytes of the log starting at byte $offset, from the live file or the archive.
     * Never reads past $limit (the size recorded for the log), so a chunk being appended right now is not half-read.
     */
    public function slice(JobLog $log, int $offset, int $length, ?int $limit = null): string
    {
        $limit ??= $log->size_bytes;
        $length = min($length, max(0, $limit - $offset));
        if ($length <= 0) {
            return '';
        }

        $disk = $this->disk();
        if ($log->storage_path !== null && $disk->exists($log->storage_path)) {
            $gz = gzopen($disk->path($log->storage_path), 'rb');
            if ($gz === false) {
                return '';
            }
            gzseek($gz, $offset);
            $data = (string) gzread($gz, $length);
            gzclose($gz);

            return $data;
        }

        $live = $this->livePath($log->runner_id);
        if (! $disk->exists($live)) {
            return '';
        }
        $file = fopen($disk->path($live), 'rb');
        if ($file === false) {
            return '';
        }
        fseek($file, $offset);
        $data = (string) fread($file, $length);
        fclose($file);

        return $data;
    }

    /** Absolute path of the gzip archive, or null while the log is still live. */
    public function archivePath(JobLog $log): ?string
    {
        $disk = $this->disk();

        return $log->storage_path !== null && $disk->exists($log->storage_path) ? $disk->path($log->storage_path) : null;
    }

    public function delete(JobLog $log): void
    {
        $this->disk()->delete(array_filter([$log->storage_path, $this->livePath($log->runner_id)]));
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('jogger.log_disk'));
    }
}
