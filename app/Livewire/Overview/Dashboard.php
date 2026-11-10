<?php

declare(strict_types=1);

namespace App\Livewire\Overview;

use App\Domain\Github\Models\GithubApp;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Actions\GetJobActivity;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use App\Support\Ui\StepData;
use App\Support\Ui\StepState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** What is happening right now: queue, runners, pools, hosts, and what failed lately. */
#[Layout('components.layouts.app')]
#[Title('Overview')]
final class Dashboard extends Component
{
    /** Seconds the aggregates are reused; a live event drops them sooner. */
    private const int CACHE_TTL = 5;

    private const string CHECKLIST_DONE = 'overview:checklist-done';

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return $this->user()->isAdmin() ? [
            'echo-private:jobs,.job.status-changed' => 'refreshStats',
            'echo-private:runners,.runner.state-changed' => 'refreshStats',
        ] : [];
    }

    public function refreshStats(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function render(GetJobActivity $activity): View
    {
        $user = $this->user();

        return view('livewire.overview.dashboard', [
            ...Cache::remember($this->cacheKey(), self::CACHE_TTL, fn () => $this->stats($user)),
            'activity' => $activity($user),
            'checklist' => $user->isAdmin() ? $this->checklist() : null,
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    /**
     * Plain arrays only, so the result can sit in the cache.
     *
     * @return array{tiles: array<string, int|null>, pools: list<array<string, mixed>>, hosts: list<array<string, mixed>>, failures: list<array<string, mixed>>}
     */
    private function stats(User $user): array
    {
        $jobs = WorkflowJob::query()->visibleTo($user)->toBase()
            ->selectRaw('count(*) filter (where status in (?, ?)) as queued', [JobStatus::Queued->value, JobStatus::Waiting->value])
            ->selectRaw('count(*) filter (where status = ?) as running', [JobStatus::InProgress->value])
            ->selectRaw('count(*) filter (where completed_at >= ? and conclusion = ?) as succeeded', [now()->subDay(), JobConclusion::Success->value])
            ->selectRaw('count(*) filter (where completed_at >= ? and conclusion in (?, ?)) as failed', [now()->subDay(), JobConclusion::Failure->value, JobConclusion::TimedOut->value])
            ->first();

        /** @var array<string, array<string, int>> $byPool */
        $byPool = [];
        foreach (Runner::query()->visibleTo($user)->live()->toBase()->selectRaw('pool_id, state, count(*) as n')->groupBy('pool_id', 'state')->get() as $row) {
            $byPool[(string) $row->pool_id][(string) $row->state] = (int) $row->n;
        }
        $count = fn (RunnerState $state): int => array_sum(array_column($byPool, $state->value));

        $succeeded = (int) ($jobs->succeeded ?? 0);
        $finished = $succeeded + (int) ($jobs->failed ?? 0);

        $pools = RunnerPool::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'status', 'max_runners'])
            ->map(fn (RunnerPool $pool): array => [
                'id' => $pool->id,
                'name' => $pool->name,
                'paused' => $pool->isPaused(),
                'max' => $pool->max_runners,
                'busy' => $byPool[$pool->id][RunnerState::Busy->value] ?? 0,
                'idle' => $byPool[$pool->id][RunnerState::Idle->value] ?? 0,
                'provisioning' => $byPool[$pool->id][RunnerState::Provisioning->value] ?? 0,
            ])->all();

        $hosts = $user->isAdmin()
            ? Host::query()->where('status', '!=', HostStatus::Revoked->value)->orderBy('name')->get(['id', 'name', 'status', 'last_seen_at'])
                ->map(fn (Host $host): array => [
                    'name' => $host->name,
                    'status' => $host->status->value,
                    'seen' => $host->last_seen_at?->diffForHumans(),
                ])->all()
            : [];

        $failures = WorkflowJob::query()->visibleTo($user)->with('repository:id,full_name')
            ->where('status', JobStatus::Completed->value)
            ->whereIn('conclusion', [JobConclusion::Failure->value, JobConclusion::TimedOut->value])
            ->orderByDesc('completed_at')->limit(5)->get()
            ->map(fn (WorkflowJob $job): array => [
                'id' => $job->id,
                'name' => $job->job_name,
                'repository' => $job->repository?->full_name,
                'conclusion' => $job->conclusion?->label(),
                'when' => $job->completed_at?->diffForHumans(),
            ])->all();

        return [
            'tiles' => [
                'queued' => (int) ($jobs->queued ?? 0),
                'running' => (int) ($jobs->running ?? 0),
                'idle' => $count(RunnerState::Idle),
                'busy' => $count(RunnerState::Busy),
                'starting' => $count(RunnerState::Provisioning),
                'finished' => $finished,
                'success_rate' => $finished > 0 ? (int) round($succeeded / $finished * 100) : null,
            ],
            'pools' => array_values($pools),
            'hosts' => array_values($hosts),
            'failures' => array_values($failures),
        ];
    }

    /**
     * Setup steps read from real state; null once every step is done (remembered, so it costs nothing afterwards).
     *
     * @return list<StepData>|null
     */
    private function checklist(): ?array
    {
        if (Cache::get(self::CHECKLIST_DONE) === true) {
            return null;
        }

        $done = [
            'Create the administrator account' => [true, null],
            'Connect a GitHub App' => [GithubApp::query()->exists(), 'So Jogger can register runners and receive job events.'],
            'Bring a host online' => [Host::query()->where('status', HostStatus::Online->value)->exists(), 'A machine running the Jogger agent and Docker.'],
            'Create a runner pool' => [RunnerPool::query()->exists(), 'Which labels to serve, with what image and limits.'],
            'Run a first job' => [WorkflowJob::query()->where('status', JobStatus::Completed->value)->exists(), 'Use runs-on: [self-hosted, …] with your pool\'s labels.'],
        ];

        if (! in_array(false, array_column($done, 0), true)) {
            Cache::forever(self::CHECKLIST_DONE, true);

            return null;
        }

        $steps = [];
        $activeGiven = false;
        foreach ($done as $label => [$ok, $detail]) {
            $state = $ok ? StepState::Done : ($activeGiven ? StepState::Pending : StepState::Active);
            $activeGiven = $activeGiven || ! $ok;
            $steps[] = new StepData($label, $ok ? null : $detail, $state);
        }

        return $steps;
    }

    private function cacheKey(): string
    {
        return 'overview:stats:'.$this->user()->id;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
