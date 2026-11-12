<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cloud\Actions\ReapUnbootedCloudHosts;
use App\Domain\Cloud\Actions\ScaleCloudHosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:scale-cloud-hosts')]
#[Description('Delete cloud servers that never booted, then request the cloud servers the waiting jobs need')]
final class ScaleCloudHostsCommand extends Command
{
    public function handle(ReapUnbootedCloudHosts $reap, ScaleCloudHosts $scale): int
    {
        $reaped = $reap();
        $requested = $scale();

        $this->info("Requested {$requested} cloud host(s); {$reaped} never booted and were removed.");

        return self::SUCCESS;
    }
}
