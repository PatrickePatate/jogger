<?php

declare(strict_types=1);

namespace App\Domain\Pools\Actions;

use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostImage;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Secrets\Contracts\ResolvesSecrets;

/**
 * Asks every host a pool may use to pull the pool's image ahead of time, so the first job does not wait for a
 * download. Purely an optimisation: a host without the image still pulls it when a runner starts there.
 */
final class EnsureImageOnHosts
{
    public function __construct(private readonly HostDriver $driver, private readonly ResolvesSecrets $secrets) {}

    /**
     * @param  bool  $force  pull again even where the image is already present (to refresh a moving tag)
     * @return int pulls requested
     */
    public function __invoke(RunnerPool $pool, bool $force = false): int
    {
        $required = array_map('mb_strtolower', $pool->allowed_host_labels);
        $requested = 0;
        $auth = $this->secrets->registryAuthFor($pool);

        $hosts = Host::query()->whereIn('status', [HostStatus::Online->value, HostStatus::Cordoned->value, HostStatus::Draining->value])->get()
            ->filter(fn (Host $host) => array_diff($required, array_map('mb_strtolower', $host->labels)) === []);

        foreach ($hosts as $host) {
            $record = HostImage::query()->firstOrNew(['host_id' => $host->id, 'image' => $pool->image]);
            if ($record->exists && ! $force && $record->status !== HostImage::FAILED) {
                continue; // already there or on its way
            }

            $command = $this->driver->send($host, 'image.pull', ['image' => $pool->image, ...($auth !== null ? ['registry_auth' => $auth] : [])]);
            $record->fill(['status' => HostImage::PENDING, 'error' => null, 'command_id' => $command->id])->save();
            $requested++;
        }

        return $requested;
    }
}
