<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** ⌘K: jump to a page, or find a job, repository, pool or host the user is allowed to see. */
final class CommandPalette extends Component
{
    private const int PER_GROUP = 5;

    /** [route, label, admin only] */
    private const array PAGES = [
        ['overview', 'Overview', false],
        ['jobs.index', 'Jobs', false],
        ['pools.index', 'Pools', false],
        ['pools.create', 'New pool', false],
        ['runners.index', 'Runners', false],
        ['hosts.index', 'Hosts', true],
        ['secrets.index', 'Secrets', false],
        ['insights.index', 'Insights', false],
        ['github.connect', 'GitHub connection', true],
        ['github.installations', 'Installations', true],
        ['github.deliveries', 'Webhook deliveries', true],
        ['admin.users', 'Users', true],
        ['admin.teams', 'Teams', true],
        ['admin.audit', 'Audit log', true],
        ['admin.settings', 'Instance settings', true],
        ['settings.security', 'Security settings', false],
        ['settings.notifications', 'Notifications', false],
        ['settings.tokens', 'API tokens', false],
    ];

    public string $query = '';

    public function render(): View
    {
        return view('livewire.shared.command-palette', ['results' => $this->results()]);
    }

    /**
     * @return list<array{group: string, label: string, detail: string|null, url: string}>
     */
    public function results(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $term = trim($this->query);

        $results = [];
        foreach (self::PAGES as [$route, $label, $adminOnly]) {
            if (Route::has($route) && (! $adminOnly || $user->isAdmin()) && ($term === '' || stripos($label, $term) !== false)) {
                $results[] = ['group' => 'Pages', 'label' => $label, 'detail' => null, 'url' => route($route)];
            }
        }
        if (mb_strlen($term) < 2) {
            return $results;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        foreach (WorkflowJob::query()->visibleTo($user)->with('repository:id,full_name')
            ->where(fn ($q) => $q->where('job_name', 'ilike', $like)->orWhere('workflow_name', 'ilike', $like))
            ->orderByDesc('id')->limit(self::PER_GROUP)->get() as $job) {
            $results[] = ['group' => 'Jobs', 'label' => $job->job_name, 'detail' => trim(($job->repository->full_name ?? '').' · '.$job->workflow_name, ' ·'), 'url' => route('jobs.show', $job)];
        }

        foreach (Repository::query()->visibleTo($user)->where('full_name', 'ilike', $like)->orderBy('full_name')->limit(self::PER_GROUP)->get() as $repository) {
            $results[] = ['group' => 'Repositories', 'label' => $repository->full_name, 'detail' => 'Jobs of this repository', 'url' => route('jobs.index', ['repository' => $repository->id])];
        }

        foreach (RunnerPool::query()->visibleTo($user)->where('name', 'ilike', $like)->orderBy('name')->limit(self::PER_GROUP)->get() as $pool) {
            $results[] = ['group' => 'Pools', 'label' => $pool->name, 'detail' => implode(', ', $pool->labels), 'url' => route('jobs.index', ['pool' => $pool->id])];
        }

        if ($user->isAdmin()) {
            foreach (Host::query()->where('status', '!=', HostStatus::Revoked->value)->where('name', 'ilike', $like)->orderBy('name')->limit(self::PER_GROUP)->get() as $host) {
                $results[] = ['group' => 'Hosts', 'label' => $host->name, 'detail' => $host->status->label(), 'url' => route('jobs.index', ['host' => $host->id])];
            }
        }

        return $results;
    }
}
