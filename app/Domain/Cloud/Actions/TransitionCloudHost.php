<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Hosts\Models\Host;

/**
 * The only way a cloud host changes cloud state. The UPDATE matches only rows still in a state that allows the
 * move, so a stale or duplicate job changes nothing and reports false.
 */
final class TransitionCloudHost
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to set with the move (provider_server_id, billed_from, …)
     */
    public function __invoke(Host $host, CloudHostState $to, array $attributes = []): bool
    {
        $changes = $attributes + ['cloud_state' => $to->value, 'cloud_state_changed_at' => now(), 'updated_at' => now()];

        $affected = Host::query()
            ->whereKey($host->getKey())
            ->whereIn('cloud_state', array_map(fn (CloudHostState $from) => $from->value, $to->previous()))
            ->update($changes);

        if ($affected === 0) {
            return false;
        }

        $host->refresh();

        return true;
    }
}
