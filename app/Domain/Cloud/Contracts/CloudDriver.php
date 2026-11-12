<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Contracts;

use App\Domain\Cloud\Data\CloudServer;
use App\Domain\Cloud\Data\ServerSpec;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;

/** One implementation per provider. Drivers never persist anything; the database is written by the actions. */
interface CloudDriver
{
    /** Label every managed server carries; its value is always 'jogger'. */
    public const MANAGED_BY_LABEL = 'managed-by';

    /** Label holding the instance id, so one Jogger never touches another's servers. */
    public const INSTANCE_LABEL = 'jogger-instance';

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(array $credentials): void;

    /** @throws CloudException */
    public function create(CloudProvider $provider, ServerSpec $spec): CloudServer;

    /** Null when the server no longer exists. */
    public function get(CloudProvider $provider, string $serverId): ?CloudServer;

    /** Deleting a server that is already gone is not an error. */
    public function delete(CloudProvider $provider, string $serverId): void;

    /** @return list<CloudServer> servers carrying this instance's labels */
    public function listManaged(CloudProvider $provider, string $instanceId): array;
}
