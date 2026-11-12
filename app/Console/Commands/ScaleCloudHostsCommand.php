<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cloud\Actions\ReapUnbootedCloudHosts;
use App\Domain\Cloud\Actions\ScaleCloudHosts;
use App\Domain\Cloud\Actions\ScaleDownCloudHosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:scale-cloud-hosts')]
#[Description('Delete cloud servers that never booted, request the cloud servers the waiting jobs need, then delete the idle ones')]
final class ScaleCloudHostsCommand extends Command
{
    public function handle(ReapUnbootedCloudHosts $reap, ScaleCloudHosts $scale, ScaleDownCloudHosts $scaleDown): int
    {
        $reaped = $reap();
        $requested = $scale();
        $deleted = $scaleDown();

        $this->info("Requested {$requested} cloud host(s); {$reaped} never booted and were removed; {$deleted} idle cloud host(s) deleted.");

        return self::SUCCESS;
    }
}
