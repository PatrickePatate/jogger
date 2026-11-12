<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cloud\Actions\ReconcileCloudHosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jogger:reconcile-cloud-hosts')]
#[Description('Compare the cloud providers\' servers with the cloud hosts: delete orphan servers, retire lost hosts, retry failed deletions')]
final class ReconcileCloudHostsCommand extends Command
{
    public function handle(ReconcileCloudHosts $reconcile): int
    {
        ['orphan_servers' => $orphans, 'lost_hosts' => $lost, 'retried' => $retried] = $reconcile();

        $this->info("Deleted {$orphans} orphan server(s); {$lost} lost host(s) retired; {$retried} failed deletion(s) retried.");

        return self::SUCCESS;
    }
}
