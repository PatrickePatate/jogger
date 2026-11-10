<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Drivers;

use App\Domain\Hosts\Actions\DispatchHostCommand;
use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;

final class AgentHostDriver implements HostDriver
{
    public function __construct(private readonly DispatchHostCommand $dispatch) {}

    public function send(Host $host, string $type, array $payload = []): HostCommand
    {
        return ($this->dispatch)($host, $type, $payload);
    }
}
