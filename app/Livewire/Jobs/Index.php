<?php

declare(strict_types=1);

namespace App\Livewire\Jobs;

use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Every job the user may see, newest first, filterable; rows update as jobs change. */
#[Layout('components.layouts.app')]
#[Title('Jobs')]
final class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    public const STATUSES = ['queued' => 'Queued', 'unmatched' => 'No matching pool', 'in_progress' => 'Running', 'success' => 'Succeeded', 'failure' => 'Failed', 'cancelled' => 'Cancelled'];

    public const RANGES = ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '' => 'All time'];

    #[Url(except: '')]
    public string $repository = '';

    #[Url(except: '')]
    public string $workflow = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $pool = '';

    #[Url(except: '')]
    public string $host = '';

    #[Url(except: '')]
    public string $range = '';

    /** Jobs of connected repositories that run on GitHub's own runners are mirrored too; hidden unless asked for. */
    #[Url(except: false)]
    public bool $hosted = false;

    /**
     * The global `jobs` channel is for administrators; everyone else follows by polling (see the view).
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return $this->user()->isAdmin() ? ['echo-private:jobs,.job.status-changed' => 'refreshJobs'] : [];
    }

    public function refreshJobs(): void
    {
        // The re-render is the point.
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['repository', 'workflow', 'status', 'pool', 'host', 'range', 'hosted'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('repository', 'workflow', 'status', 'pool', 'host', 'range', 'hosted');
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.jobs.index', [
            'jobs' => $this->query($user)->with(['repository'])->orderByDesc('id')->cursorPaginate(self::PER_PAGE),
            'repositories' => Repository::query()->visibleTo($user)->orderBy('full_name')->limit(500)->get(['id', 'full_name']),
            'pools' => RunnerPool::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'hosts' => Host::query()->orderBy('name')->get(['id', 'name']),
            'filtered' => $this->repository.$this->workflow.$this->status.$this->pool.$this->host.$this->range !== '',
            'polls' => ! $user->isAdmin(),
            // Pools that a cloud template may create servers for: one query per page, not one per job.
            'cloudPools' => RunnerPool::query()->whereHas('templates', fn ($q) => $q->where('host_templates.status', 'active'))->pluck('id')->flip()->all(),
        ]);
    }

    /**
     * @return Builder<WorkflowJob>
     */
    private function query(User $user): Builder
    {
        $query = WorkflowJob::query()->visibleTo($user);

        if ($this->repository !== '') {
            $query->where('repository_id', $this->repository);
        }
        if (! $this->hosted) {
            $query->onJogger();
        }
        if ($this->workflow !== '') {
            $query->where('workflow_name', 'ilike', '%'.addcslashes($this->workflow, '%_\\').'%');
        }
        if ($this->pool !== '') {
            $query->where('pool_id', $this->pool);
        }
        if ($this->host !== '') {
            $query->whereIn('runner_id', Runner::query()->where('host_id', $this->host)->select('id'));
        }

        match ($this->status) {
            'queued' => $query->where('status', JobStatus::Queued->value),
            'unmatched' => $query->where('status', JobStatus::Queued->value)->whereNotNull('unmatched_reason'),
            'in_progress' => $query->where('status', JobStatus::InProgress->value),
            'success', 'cancelled' => $query->where('status', JobStatus::Completed->value)->where('conclusion', $this->status),
            'failure' => $query->where('status', JobStatus::Completed->value)->whereIn('conclusion', ['failure', 'timed_out']),
            default => null,
        };

        $since = match ($this->range) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };
        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
