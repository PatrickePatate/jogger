<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Access\Audit;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Contracts\RunnerCensus;

/**
 * Asks a host's agent to replace itself with the release this server runs. Only for a host with no runners
 * (drained or cordoned): the new container starts a few seconds after the old one stops.
 */
final class RequestAgentUpdate
{
    public function __construct(
        private readonly DispatchHostCommand $dispatch,
        private readonly RunnerCensus $census,
    ) {}

    /**
     * @return bool false when nothing was requested (no newer version, host not online, or runners still active)
     */
    public function __invoke(Host $host): bool
    {
        $version = $host->availableAgentVersion();

        if ($version === null
            || ! in_array($host->status, [HostStatus::Online, HostStatus::Cordoned, HostStatus::Draining], true)
            || $this->census->activeOn($host) > 0) {
            return false;
        }

        $repository = preg_replace('/(:[^\/:]+)?$/', '', (string) config('jogger.cloud.agent_image'));
        $image = "{$repository}:{$version}";

        ($this->dispatch)($host, 'agent.update', ['image' => $image, 'version' => $version]);
        Audit::record('host.agent_update_requested', $host, ['name' => $host->name, 'from' => $host->agent_version, 'to' => $version]);

        return true;
    }
}
