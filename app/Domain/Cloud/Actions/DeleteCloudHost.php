<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Access\Audit;
use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Hosts\Actions\RevokeHost;
use App\Domain\Hosts\Models\Host;

/**
 * Deletes a cloud host's server and retires the host. The host leaves its state for deleting first, which stops
 * PickHost from placing runners on it. On success the agent's credential is revoked, the host is deleted and its row
 * is soft-deleted. A provider refusal leaves the host in failed, where reconciliation retries it.
 */
final class DeleteCloudHost
{
    public function __construct(
        private readonly CloudManager $clouds,
        private readonly TransitionCloudHost $transition,
        private readonly RevokeHost $revoke,
    ) {}

    /** @return bool false when the host's state refuses the move to deleting, or the provider refused the delete */
    public function __invoke(Host $host, string $reason): bool
    {
        return $this->remove($host, $reason, providerCall: true);
    }

    /**
     * The provider no longer lists the server, so there is nothing to delete there. Retires the host the same way and
     * audits it as lost rather than deleted.
     */
    public function forget(Host $host, string $reason): bool
    {
        return $this->remove($host, $reason, providerCall: false);
    }

    private function remove(Host $host, string $reason, bool $providerCall): bool
    {
        if (! ($this->transition)($host, CloudHostState::Deleting)) {
            return false;
        }

        if ($providerCall) {
            try {
                $this->destroy($host);
            } catch (CloudException $e) {
                ($this->transition)($host, CloudHostState::Failed);
                Audit::record('cloud.host_delete_failed', $host, [
                    'name' => $host->name,
                    'server' => $host->provider_server_id,
                    'reason' => $e->reason,
                    'why' => $reason,
                ]);

                return false;
            }
        }

        ($this->revoke)($host);
        ($this->transition)($host, CloudHostState::Deleted);
        // Soft-deleted last: the transitions above match only rows the default scope still sees.
        $host->delete();

        Audit::record($providerCall ? 'cloud.host_deleted' : 'cloud.host_lost', $host, [
            'name' => $host->name,
            'server' => $host->provider_server_id,
            'reason' => $reason,
        ]);

        return true;
    }

    /** @throws CloudException when the provider refuses, or the host has no provider to ask */
    private function destroy(Host $host): void
    {
        if ($host->provider_server_id === null) {
            return;
        }

        $provider = $host->template?->provider;
        if ($provider === null) {
            throw CloudException::noProvider('The template of this host, and so its provider, no longer exists.');
        }

        $this->clouds->for($provider)->delete($provider, $host->provider_server_id);
    }
}
