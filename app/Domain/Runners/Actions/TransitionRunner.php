<?php

declare(strict_types=1);

namespace App\Domain\Runners\Actions;

use App\Domain\Hosts\Actions\DrainHost;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Events\RunnerStateChanged;
use Illuminate\Support\Facades\DB;

/**
 * The only way a runner changes state. The UPDATE matches only rows still in a state that allows the move, so a
 * stale or duplicate event changes nothing, throws nothing and broadcasts nothing.
 */
final class TransitionRunner
{
    public function __construct(private readonly DrainHost $drain) {}

    /**
     * @param  array<string, mixed>  $attributes  extra columns to set with the move (exit_reason, exit_code, …)
     */
    public function __invoke(Runner $runner, RunnerState $to, array $attributes = []): bool
    {
        $changes = $attributes + ['state' => $to->value, 'state_changed_at' => now(), 'updated_at' => now()];

        if ($to === RunnerState::Idle || $to === RunnerState::Busy) {
            $changes['started_at'] = DB::raw('COALESCE(started_at, now())');
        }
        if ($to->isTerminal()) {
            $changes['finished_at'] = now();
        }

        $affected = Runner::query()
            ->whereKey($runner->getKey())
            ->whereIn('state', RunnerState::values($to->previous()))
            ->update($changes);

        if ($affected === 0) {
            return false;
        }

        $runner->refresh();
        event(new RunnerStateChanged($runner));

        // A draining host may have just lost its last runner.
        if ($to->isTerminal() && $runner->host !== null) {
            $this->drain->settle($runner->host);
        }

        return true;
    }
}
