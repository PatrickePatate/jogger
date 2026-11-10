<?php

declare(strict_types=1);

namespace App\Livewire\Jobs;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobLog;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use App\Support\Ui\Duration;
use App\Support\Ui\StepData;
use App\Support\Ui\StepState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** One job: where it is in its life, what ran it, and its log, all live. */
#[Layout('components.layouts.app')]
final class Show extends Component
{
    public string $jobId;

    public function mount(string $job): void
    {
        $this->jobId = $this->find($job)->id;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ["echo-private:job.{$this->jobId},.job.status-changed" => 'refreshJob'];
    }

    public function refreshJob(): void
    {
        // The re-render is the point.
    }

    public function render(): View
    {
        $job = $this->find($this->jobId);
        $runner = $job->runner_id !== null ? Runner::query()->with(['host', 'pool'])->find($job->runner_id) : null;

        return view('livewire.jobs.show', [
            'job' => $job,
            'runner' => $runner,
            'pool' => $runner !== null ? $runner->pool : $job->pool,
            'steps' => $this->steps($job, $runner),
            'log' => JobLog::query()->where('job_id', $job->id)->first(),
            // Still moving: keep refreshing for the things no broadcast covers (runner state, durations).
            'live' => $job->isOpen(),
        ])->title($job->job_name);
    }

    /**
     * Queued → Provisioning → Running → Completed, with how long each took.
     *
     * @return list<StepData>
     */
    private function steps(WorkflowJob $job, ?Runner $runner): array
    {
        $status = $job->status;
        $started = $status->rank() >= JobStatus::InProgress->rank();
        $completed = $status === JobStatus::Completed;
        $neverRan = $completed && $job->runner_id === null && $job->started_at === null;
        $reason = $job->unmatched_reason !== null ? Str::after($job->unmatched_reason, ': ') : null;

        // While queued, "provisioning" is what the pool's newest starting runner is doing.
        $provisioning = ! $started && $job->pool_id !== null
            ? Runner::query()->where('pool_id', $job->pool_id)->where('created_at', '>=', $job->queued_at ?? $job->created_at)->latest('created_at')->first()
            : null;

        $steps = [
            new StepData('Queued', $job->queued_at?->toDayDateTimeString(), StepState::Done),
        ];

        $steps[] = match (true) {
            $started => new StepData('Provisioning', 'Runner ready after '.(Duration::between($job->queued_at, $job->started_at) ?? '—'), StepState::Done),
            $neverRan => new StepData('Provisioning', 'No runner picked this job up', StepState::Skipped),
            $job->pool_id === null && $reason !== null => new StepData('Provisioning', ucfirst($reason), StepState::Failed),
            $provisioning !== null && $provisioning->state === RunnerState::Failed => new StepData('Provisioning', 'Runner failed to start ('.str_replace('_', ' ', (string) $provisioning->exit_reason).'); retrying', StepState::Failed),
            $reason !== null => new StepData('Provisioning', ucfirst($reason), StepState::Active),
            $job->pool_id === null => new StepData('Provisioning', 'Not a Jogger job (GitHub-hosted labels)', StepState::Skipped),
            default => new StepData('Provisioning', 'Waiting for a runner for '.(Duration::between($job->queued_at, now()) ?? '—'), StepState::Active),
        };

        $steps[] = match (true) {
            $completed && $job->started_at !== null => new StepData('Running', 'Ran for '.(Duration::between($job->started_at, $job->completed_at) ?? '—'), StepState::Done),
            $neverRan => new StepData('Running', null, StepState::Skipped),
            $started => new StepData('Running', 'Running for '.(Duration::between($job->started_at, now()) ?? '—').($runner ? " on {$runner->name}" : ''), StepState::Active),
            default => new StepData('Running'),
        };

        $conclusion = $job->conclusion?->value;
        $steps[] = match (true) {
            ! $completed => new StepData('Completed'),
            in_array($conclusion, ['failure', 'timed_out'], true) => new StepData('Completed', ucfirst(str_replace('_', ' ', (string) $conclusion)), StepState::Failed),
            default => new StepData('Completed', ucfirst(str_replace('_', ' ', $conclusion ?? 'done')).' · '.$job->completed_at?->diffForHumans(), StepState::Done),
        };

        return $steps;
    }

    private function find(string $id): WorkflowJob
    {
        abort_unless(Str::isUuid($id), 404);
        /** @var User $user */
        $user = Auth::user();

        return WorkflowJob::query()->visibleTo($user)->with(['repository', 'pool'])->findOrFail($id);
    }
}
