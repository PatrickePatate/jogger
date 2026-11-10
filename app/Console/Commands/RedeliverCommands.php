<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hosts\Enums\CommandStatus;
use App\Domain\Hosts\Events\HostCommandAvailable;
use App\Domain\Hosts\Models\HostCommand;
use Illuminate\Console\Command;

final class RedeliverCommands extends Command
{
    protected $signature = 'jogger:redeliver-commands';

    protected $description = 'Offer host commands that were sent but never acknowledged again, or give up on them';

    public function handle(): int
    {
        $stale = HostCommand::query()
            ->where('status', CommandStatus::Sent->value)
            ->where('sent_at', '<', now()->subSeconds(HostCommand::REDELIVER_AFTER))
            ->get();

        $again = $failed = 0;

        foreach ($stale as $command) {
            $exhausted = $command->attempts >= HostCommand::MAX_ATTEMPTS;

            // Conditional on still being `sent`: an ack that lands meanwhile wins.
            $changed = HostCommand::query()->whereKey($command->id)->where('status', CommandStatus::Sent->value)->update([
                'status' => ($exhausted ? CommandStatus::Failed : CommandStatus::Pending)->value,
                'result' => $exhausted ? json_encode(['error' => 'not acknowledged after '.HostCommand::MAX_ATTEMPTS.' attempts']) : null,
                'updated_at' => now(),
            ]);

            if ($changed === 0) {
                continue;
            }

            if ($exhausted) {
                $failed++;
            } else {
                event(new HostCommandAvailable($command->host_id, $command->id));
                $again++;
            }
        }

        $this->info("Re-offered {$again} command(s), gave up on {$failed}.");

        return self::SUCCESS;
    }
}
