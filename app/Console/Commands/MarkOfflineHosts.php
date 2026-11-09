<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Events\HostStatusChanged;
use App\Domain\Hosts\Models\Host;
use Illuminate\Console\Command;

final class MarkOfflineHosts extends Command
{
    protected $signature = 'jogger:mark-offline-hosts';

    protected $description = 'Mark hosts that stopped sending heartbeats as offline';

    /** Cordoned and draining hosts keep the state an operator chose; only "online" can lapse into "offline". */
    public function handle(): int
    {
        $cutoff = now()->subSeconds((int) config('jogger.host_offline_after'));
        $count = 0;

        Host::query()
            ->whereIn('status', [HostStatus::Online->value])
            ->where('last_seen_at', '<', $cutoff)
            ->each(function (Host $host) use ($cutoff, &$count) {
                // Conditional update: a heartbeat that lands meanwhile wins.
                $affected = Host::query()->whereKey($host->id)->where('last_seen_at', '<', $cutoff)
                    ->whereIn('status', [HostStatus::Online->value])
                    ->update(['status' => HostStatus::Offline->value, 'updated_at' => now()]);

                if ($affected > 0) {
                    event(new HostStatusChanged($host->refresh()));
                    $count++;
                }
            });

        $this->info("Marked {$count} host(s) offline.");

        return self::SUCCESS;
    }
}
