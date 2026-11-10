<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Contracts;

use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;

/**
 * How the control plane tells a host to do something. v1 has one driver (the agent); the interface keeps the
 * scheduler independent of it. Runner-specific helpers (startRunner/stopRunner) are added with the runner model.
 */
interface HostDriver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(Host $host, string $type, array $payload = []): HostCommand;
}
