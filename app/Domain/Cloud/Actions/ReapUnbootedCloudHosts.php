<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Access\Audit;
use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Hosts\Models\Host;

/**
 * Deletes cloud servers that did not enroll within the boot timeout. The host leaves requested or booting for
 * deleting first, so an enrollment that arrives meanwhile is refused and cannot race the delete.
 */
final class ReapUnbootedCloudHosts
{
    public function __construct(
        private readonly CloudManager $clouds,
        private readonly TransitionCloudHost $transition,
    ) {}

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
                if (! ($this->transition)($host, CloudHostState::Deleting)) {
                    return;
                }
                $reaped++;
                Audit::record('cloud.host_boot_timeout', $host, ['name' => $host->name, 'server' => $host->provider_server_id]);

                if ($this->destroy($host)) {
                    ($this->transition)($host, CloudHostState::Deleted);
                } else {
                    // Left in failed; the reconciliation pass (step C4) retries servers it finds still running.
                    ($this->transition)($host, CloudHostState::Failed);
                }
            });

        return $reaped;
    }

    private function destroy(Host $host): bool
    {
        if ($host->provider_server_id === null) {
            return true;
        }

        $provider = $host->template?->provider;
        if ($provider === null) {
            Audit::record('cloud.host_delete_failed', $host, ['reason' => 'no_provider']);

            return false;
        }

        try {
            $this->clouds->for($provider)->delete($provider, $host->provider_server_id);

            return true;
        } catch (CloudException $e) {
            Audit::record('cloud.host_delete_failed', $host, ['reason' => $e->reason]);

            return false;
        }
    }
}
