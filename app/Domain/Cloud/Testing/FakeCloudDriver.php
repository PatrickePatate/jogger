<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Testing;

use App\Domain\Cloud\Contracts\CloudDriver;
use App\Domain\Cloud\Data\CloudServer;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use Carbon\CarbonImmutable;

/**
 * In-memory provider for tests. Bound as a singleton so the code under test and the test share one instance.
 */
final class FakeCloudDriver implements CloudDriver
{
    /** @var array<string, CloudServer> */
    private array $servers = [];

    private int $sequence = 0;

    private ?CloudException $nextCreateFailure = null;

    /**
     * Every spec passed to create(), in order.
     *
     * @var list<ServerSpec>
     */
    public array $created = [];

    /**
     * Every id passed to delete(), in order, including servers that were already gone.
     *
     * @var list<string>
     */
    public array $deleted = [];

    public function validateCredentials(array $credentials): void
    {
        // Accepts any credentials: the fake has no account to check them against.
    }

    public function create(CloudProvider $provider, ServerSpec $spec): CloudServer
    {
        if ($this->nextCreateFailure !== null) {
            $failure = $this->nextCreateFailure;
            $this->nextCreateFailure = null;

            throw $failure;
        }

        $number = ++$this->sequence;
        $server = new CloudServer(
            id: "fake-{$number}",
            name: $spec->name,
            status: 'running',
            ipv4: $spec->ipv4 ? sprintf('203.0.113.%d', ($number % 250) + 1) : null,
            ipv6: null,
            createdAt: CarbonImmutable::now(),
            labels: $spec->labels,
        );

        $this->servers[$server->id] = $server;
        $this->created[] = $spec;

        return $server;
    }

    public function get(CloudProvider $provider, string $serverId): ?CloudServer
    {
        return $this->servers[$serverId] ?? null;
    }

    public function delete(CloudProvider $provider, string $serverId): void
    {
        unset($this->servers[$serverId]);
        $this->deleted[] = $serverId;
    }

    public function listManaged(CloudProvider $provider, string $instanceId): array
    {
        return array_values(array_filter(
            $this->servers,
            fn (CloudServer $server) => ($server->labels[self::MANAGED_BY_LABEL] ?? null) === 'jogger'
                && ($server->labels[self::INSTANCE_LABEL] ?? null) === $instanceId,
        ));
    }

    /** @return list<CloudServer> */
    public function servers(): array
    {
        return array_values($this->servers);
    }

    /** The next create() throws this; later calls succeed again. */
    public function failNextCreate(CloudException $e): void
    {
        $this->nextCreateFailure = $e;
    }
}
