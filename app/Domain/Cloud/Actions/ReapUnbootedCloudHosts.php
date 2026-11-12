<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Access\Audit;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Hosts\Models\Host;

/**
 * Deletes cloud servers that did not enroll within the boot timeout. The deletion itself is DeleteCloudHost's, which
 * leaves requested or booting first, so an enrollment that arrives meanwhile is refused and cannot race the delete.
 */
final class ReapUnbootedCloudHosts
{
    public function __construct(private readonly DeleteCloudHost $delete) {}

    /** @return int hosts that timed out */
    public function __invoke(): int
    {
        $cutoff = now()->subSeconds((int) config('jogger.cloud.boot_timeout_seconds'));
        $reaped = 0;

        Host::query()
            ->with('template.provider')
            ->whereIn('cloud_state', [CloudHostState::Requested->value, CloudHostState::Booting->value])
            ->where('cloud_state_changed_at', '<', $cutoff)
            ->get()
            ->each(function (Host $host) use (&$reaped) {
                ($this->delete)($host, 'boot_timeout');

                // Refused moves (the host came online, or someone else is deleting it) leave some other state.
                if (! in_array($host->refresh()->cloud_state, [CloudHostState::Deleted, CloudHostState::Failed], true)) {
                    return;
                }

                $reaped++;
                Audit::record('cloud.host_boot_timeout', $host, ['name' => $host->name, 'server' => $host->provider_server_id]);
            });

        return $reaped;
    }
}
