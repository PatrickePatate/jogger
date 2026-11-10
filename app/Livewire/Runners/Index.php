<?php

declare(strict_types=1);

namespace App\Livewire\Runners;

use App\Domain\Access\Audit;
use App\Domain\Runners\Actions\TerminateRunner;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use App\Support\Ui\Duration;
use App\Support\Ui\StepData;
use App\Support\Ui\StepState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Runners that exist now, plus those that ended in the last day, with what each is doing. */
#[Layout('components.layouts.app')]
#[Title('Runners')]
final class Index extends Component
{
    #[Url(except: '')]
    public string $state = '';

    /** Runner whose details are open. */
    public ?string $selected = null;

    /** A busy runner the user asked to terminate and must confirm again. */
    public ?string $confirmingForce = null;

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return $this->user()->isAdmin() ? ['echo-private:runners,.runner.state-changed' => 'refreshRunners'] : [];
    }

    public function refreshRunners(): void
    {
        // The re-render is the point.
    }

    public function select(?string $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
    }

    /** Idle and starting runners end at once; a busy one kills a running job and needs `$force`. */
    public function terminate(string $id, TerminateRunner $terminate, bool $force = false): void
    {
        $runner = Runner::query()->visibleTo($this->user())->with('pool')->findOrFail($id);
        $this->authorize('update', $runner->pool);

        if ($runner->state === RunnerState::Busy && ! $force) {
            $this->confirmingForce = $runner->id;

            return;
        }
        $this->confirmingForce = null;

        if ($terminate($runner, 'terminated_by_user')) {
            Audit::record('runner.terminated', $runner, ['name' => $runner->name, 'forced' => $force, 'pool' => $runner->pool->name]);
            $this->dispatch('toast', type: 'success', message: "Stopping {$runner->name}.");
        }
    }

    public function cancelForce(): void
    {
        $this->confirmingForce = null;
    }

    public function render(): View
    {
        $user = $this->user();

        $runners = Runner::query()->visibleTo($user)->with(['pool', 'host', 'job'])
            ->when($this->state !== '', fn ($q) => $q->where('state', $this->state))
            ->when($this->state === '', fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('state', RunnerState::values(RunnerState::present()))
                ->orWhere('finished_at', '>', now()->subDay())))
            ->orderByRaw("CASE WHEN state IN ('gone','failed') THEN 1 ELSE 0 END")->orderByDesc('id')
            ->limit(200)->get();

        return view('livewire.runners.index', [
            'runners' => $runners,
            'states' => RunnerState::cases(),
            'polls' => ! $user->isAdmin(),
        ]);
    }

    /**
     * The runner's life as steps, for the details panel.
     *
     * @return list<StepData>
     */
    public function lifecycle(Runner $runner): array
    {
        $state = $runner->state;
        $failed = $state === RunnerState::Failed;
        $reason = $runner->exit_reason !== null ? str_replace('_', ' ', $runner->exit_reason) : null;
        $ran = $runner->job !== null;

        return [
            new StepData('Requested', $runner->created_at?->toDayDateTimeString(), StepState::Done),
            match (true) {
                $runner->started_at !== null => new StepData('Container started', 'After '.(Duration::between($runner->created_at, $runner->started_at) ?? '—'), StepState::Done),
                $failed => new StepData('Container started', ucfirst($reason ?? 'failed'), StepState::Failed),
                $state === RunnerState::Provisioning => new StepData('Container started', 'Starting on '.($runner->host->name ?? 'its host'), StepState::Active),
                default => new StepData('Container started', null, StepState::Skipped),
            },
            match (true) {
                $ran && $state === RunnerState::Busy => new StepData('Running a job', $runner->job->job_name, StepState::Active),
                $ran => new StepData('Ran a job', $runner->job->job_name, StepState::Done),
                $state === RunnerState::Idle => new StepData('Waiting for a job', 'Idle for '.(Duration::between($runner->state_changed_at, now()) ?? '—'), StepState::Active),
                $state->isLive() => new StepData('Waiting for a job'),
                default => new StepData('Ran a job', 'Never picked up a job', StepState::Skipped),
            },
            match (true) {
                $failed && $runner->started_at !== null => new StepData('Ended', ucfirst($reason ?? 'failed').($runner->exit_code !== null ? " (exit {$runner->exit_code})" : ''), StepState::Failed),
                $state === RunnerState::Gone => new StepData('Ended', ucfirst($reason ?? 'done').' · '.$runner->finished_at?->diffForHumans(), StepState::Done),
                $state === RunnerState::Terminating => new StepData('Ending', ucfirst($reason ?? 'stopping'), StepState::Active),
                $failed => new StepData('Ended', null, StepState::Skipped),
                default => new StepData('Ended'),
            },
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
