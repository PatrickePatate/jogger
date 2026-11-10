<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Github\Actions\ReconcileJobs;
use App\Domain\Github\Actions\ReconcileRunners;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('jogger:reconcile')]
#[Description('Compare jobs and runners with GitHub and repair differences (missed webhooks, leftover runners)')]
final class Reconcile extends Command
{
    public const LOCK = 'jogger:reconcile';

    public function handle(ReconcileJobs $jobs, ReconcileRunners $runners): int
    {
        // Single flight: a slow pass must not be joined by the next one. The lock expires on its own if we die.
        $lock = Cache::lock(self::LOCK, 300);
        if (! $lock->get()) {
            $this->line('Another reconcile is running.');

            return self::SUCCESS;
        }

        try {
            $imported = $jobs();
            $result = $runners();
            $this->info("Imported {$imported} job(s), deleted {$result['deleted']} GitHub runner(s), failed {$result['failed']} runner(s).");
        } catch (GithubRateLimited) {
            $this->warn('GitHub rate limit reached; will try again next time.');
        } catch (GithubApiError $e) {
            $this->warn("GitHub error: {$e->getMessage()}");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
