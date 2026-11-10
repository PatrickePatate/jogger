<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Logs\LogStore;
use App\Domain\Jobs\Models\JobLog;
use App\Domain\Jobs\Models\WorkflowJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:prune')]
#[Description('Delete data past its retention period: webhook deliveries, job logs, and finished jobs')]
final class Prune extends Command
{
    public function handle(LogStore $store): int
    {
        $deliveryDays = max(1, (int) config('jogger.delivery_retention_days'));
        $deliveries = WebhookDelivery::query()->where('received_at', '<', now()->subDays($deliveryDays))->delete();

        // Logs: expired ones, and those of jobs about to be deleted (the row would cascade, the file would not).
        $jobDays = max(1, (int) config('jogger.job_retention_days'));
        $oldJobs = WorkflowJob::query()->where('status', JobStatus::Completed->value)->where('completed_at', '<', now()->subDays($jobDays));

        $logs = 0;
        JobLog::query()
            ->where(fn ($q) => $q->where('expires_at', '<', now())->orWhereIn('job_id', (clone $oldJobs)->select('id')))
            ->eachById(function (JobLog $log) use ($store, &$logs) {
                $store->delete($log);
                $log->delete();
                $logs++;
            }, 200, 'runner_id');

        $jobs = $oldJobs->delete();

        $this->info("Deleted {$deliveries} webhook deliveries older than {$deliveryDays} days, {$logs} job logs, {$jobs} jobs older than {$jobDays} days.");

        return self::SUCCESS;
    }
}
