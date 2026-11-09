<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Github\Models\WebhookDelivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:prune')]
#[Description('Delete data past its retention period (webhook deliveries; job logs and jobs follow in later tasks)')]
final class Prune extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) config('jogger.delivery_retention_days'));

        $deleted = WebhookDelivery::query()
            ->where('received_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} webhook deliveries older than {$days} days.");

        return self::SUCCESS;
    }
}
