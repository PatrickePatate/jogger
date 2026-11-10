<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:prune-hosts')]
#[Description('Ask every reachable host to remove dangling images and leftovers and to trim its Docker build cache')]
final class PruneHosts extends Command
{
    public function handle(HostDriver $driver): int
    {
        $hosts = Host::query()->whereIn('status', [HostStatus::Online->value, HostStatus::Cordoned->value, HostStatus::Draining->value])->get();
        foreach ($hosts as $host) {
            $driver->send($host, 'host.prune', ['max_build_cache_gb' => (float) config('jogger.build_cache_max_gb')]);
        }
        $this->info("Prune requested on {$hosts->count()} host(s).");

        return self::SUCCESS;
    }
}
