<?php

declare(strict_types=1);

namespace App\Domain\Hosts\Actions;

use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Hosts\Models\HostImage;
use App\Domain\Runners\Actions\TerminateRunner;
use App\Domain\Runners\Actions\TransitionRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Support\Str;

/** What an acknowledged command means for the rest of the system. Runs once per command, when it is settled. */
final class ApplyCommandResult
{
    public function __construct(private readonly TransitionRunner $transition, private readonly TerminateRunner $terminate) {}

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function __invoke(Host $host, HostCommand $command, bool $ok, ?array $result): void
    {
        match ($command->type) {
            'runner.start' => $this->runnerStart($host, $command, $ok),
            'image.pull' => $this->imagePull($command, $ok, $result),
            default => null,
        };
    }

    /** A runner whose container could not be started must not hold its slot until the provision timeout. */
    private function runnerStart(Host $host, HostCommand $command, bool $ok): void
    {
        $runnerId = (string) ($command->payload['runner_id'] ?? '');
        if ($ok || ! Str::isUuid($runnerId)) {
            return;
        }

        $runner = Runner::query()->whereKey($runnerId)->where('host_id', $host->id)->first();
        if ($runner !== null && ($this->transition)($runner, RunnerState::Failed, ['exit_reason' => 'start_failed'])) {
            $this->terminate->deregister($runner);
        }
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function imagePull(HostCommand $command, bool $ok, ?array $result): void
    {
        HostImage::query()->where('command_id', $command->id)->update($ok
            ? ['status' => HostImage::READY, 'error' => null, 'pulled_at' => now(), 'updated_at' => now()]
            : ['status' => HostImage::FAILED, 'error' => Str::limit((string) ($result['error'] ?? 'pull failed'), 500), 'updated_at' => now()]);
    }
}
