<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Contracts\RunnerEvents;

/** Routes one agent event to the part of the system that owns it. Unknown types are ignored: old servers, new agents. */
final class ApplyAgentEvent
{
    public const CONTAINER_STARTED = 'container_started';

    public const CONTAINER_EXITED = 'container_exited';

    public function __construct(private readonly RunnerEvents $runners) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Host $host, string $type, string $runnerId, array $data = []): bool
    {
        switch ($type) {
            case self::CONTAINER_STARTED:
                $this->runners->containerStarted($host, $runnerId);

                return true;
            case self::CONTAINER_EXITED:
                $code = $data['exit_code'] ?? null;
                $this->runners->containerExited($host, $runnerId, is_numeric($code) ? (int) $code : null, (bool) ($data['oom'] ?? false));

                return true;
        }

        return false;
    }
}
