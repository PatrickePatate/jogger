<?php

declare(strict_types=1);

namespace App\Livewire\Github;

use App\Domain\Github\Actions\SyncInstallation;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Installations')]
final class Installations extends Component
{
    use WithPagination;

    #[Url(as: 'installation')]
    public string $selectedId = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function select(string $installationId): void
    {
        $this->selectedId = Installation::query()->findOrFail($installationId)->id;
        $this->search = '';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resync(string $installationId, SyncInstallation $sync): void
    {
        Gate::authorize('admin');

        try {
            $result = $sync(Installation::query()->findOrFail($installationId));
        } catch (GithubRateLimited $e) {
            $this->dispatch('toast', type: 'warning', message: "GitHub is rate limiting Jogger. Try again in {$e->retryAfterSeconds} seconds.");

            return;
        } catch (GithubApiError $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: $result->summary());
    }

    public function render(): View
    {
        $installations = Installation::query()->withCount('repositories')->orderBy('account_login')->get();
        $selected = $installations->firstWhere('id', $this->selectedId) ?? $installations->first();
        $app = GithubApp::query()->first();

        $repositories = $selected
            ? Repository::query()
                ->where('installation_id', $selected->id)
                ->when($this->search !== '', fn ($q) => $q->where('full_name', 'ilike', '%'.$this->search.'%'))
                ->orderBy('full_name')
                ->paginate(50)
            : null;

        return view('livewire.github.installations', [
            'installations' => $installations,
            'selected' => $selected,
            'repositories' => $repositories,
            'publicCount' => $selected ? Repository::query()->where('installation_id', $selected->id)->where('private', false)->count() : 0,
            'installUrl' => $app ? rtrim((string) config('jogger.github.web_url'), '/')."/apps/{$app->slug}/installations/new" : null,
        ]);
    }
}
