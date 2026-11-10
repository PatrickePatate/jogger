<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\EvaluateAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:evaluate-alerts')]
#[Description('Raise alerts for new problems (offline hosts, failing pools, GitHub auth, disk, webhooks) and resolve cleared ones')]
final class EvaluateAlertsCommand extends Command
{
    public function handle(EvaluateAlerts $evaluate): int
    {
        $result = $evaluate();
        $this->info("{$result['fired']} fired, {$result['resolved']} resolved.");

        return self::SUCCESS;
    }
}
