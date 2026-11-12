<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Access\Audit;
use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Data\CloudServer;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Hosts\Models\Host;

/**
 * Makes the providers' servers and the hosts agree, per active provider:
 *   - a managed server that no open or failed host claims, and that is older than the orphan age, is deleted;
 *   - a host in booting, online or draining whose server the provider no longer lists is retired as lost;
 *   - a failed host that still has a server gets its delete retried.
 * A provider whose listing fails is skipped entirely: an error must never read as "no servers".
 */
final class ReconcileCloudHosts
{
    /** A server younger than this may not be recorded on its host yet (the create and the row are separate steps). */
    public const ORPHAN_AGE_MINUTES = 10;

    public function __construct(
        private readonly CloudManager $clouds,
        private readonly DeleteCloudHost $delete,
    ) {}

    /** @return array{orphan_servers: int, lost_hosts: int, retried: int} */
    public function __invoke(): array
    {
        $totals = ['orphan_servers' => 0, 'lost_hosts' => 0, 'retried' => 0];

        CloudProvider::query()->where('status', 'active')->orderBy('name')->get()
            ->each(function (CloudProvider $provider) use (&$totals) {
                foreach ($this->reconcile($provider) as $counter => $count) {
                    $totals[$counter] += $count;
                }
            });

        return $totals;
    }

    /** @return array{orphan_servers: int, lost_hosts: int, retried: int} */
    private function reconcile(CloudProvider $provider): array
    {
        $counts = ['orphan_servers' => 0, 'lost_hosts' => 0, 'retried' => 0];

        // The host snapshot is read before the servers are listed. A host created in between is then not in the
        // snapshot, so its server can only be an orphan candidate, which the age guard protects.
        $claimed = array_fill_keys(
            Host::query()
                ->whereNotNull('provider_server_id')
                ->whereIn('cloud_state', [...CloudHostState::open(), CloudHostState::Failed])
                ->pluck('provider_server_id')
                ->all(),
            true,
        );
        $ofProvider = Host::query()
            ->whereHas('template', fn ($template) => $template->where('cloud_provider_id', $provider->id))
            ->whereNotNull('provider_server_id')
            ->whereIn('cloud_state', [CloudHostState::Booting, CloudHostState::Online, CloudHostState::Draining])
            ->get();
        $failed = Host::query()
            ->whereHas('template', fn ($template) => $template->where('cloud_provider_id', $provider->id))
            ->whereNotNull('provider_server_id')
            ->where('cloud_state', CloudHostState::Failed->value)
            ->get();

        try {
            $servers = $this->clouds->for($provider)->listManaged($provider, ProvisionCloudHost::instanceId());
        } catch (CloudException $e) {
            Audit::record('cloud.reconcile_failed', $provider, ['reason' => $e->reason]);

            return $counts;
        }

        $listed = array_fill_keys(array_map(fn (CloudServer $server) => $server->id, $servers), true);

        foreach ($ofProvider as $host) {
            if (! isset($listed[$host->provider_server_id]) && $this->delete->forget($host, 'server_missing')) {
                $counts['lost_hosts']++;
            }
        }

        foreach ($servers as $server) {
            if (isset($claimed[$server->id]) || $this->isYoung($server)) {
                continue;
            }

            try {
                $this->clouds->for($provider)->delete($provider, $server->id);
            } catch (CloudException $e) {
                Audit::record('cloud.reconcile_failed', $provider, ['reason' => $e->reason, 'server' => $server->id]);

                continue;
            }

            $counts['orphan_servers']++;
            Audit::record('cloud.orphan_server_deleted', $provider, ['server' => $server->id, 'name' => $server->name]);
        }

        foreach ($failed as $host) {
            ($this->delete)($host, 'retry');
            $counts['retried']++;
        }

        return $counts;
    }

    private function isYoung(CloudServer $server): bool
    {
        return $server->createdAt === null || $server->createdAt->gt(now()->subMinutes(self::ORPHAN_AGE_MINUTES));
    }
}
