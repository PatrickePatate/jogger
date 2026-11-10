<?php

declare(strict_types=1);

namespace App\Livewire\Pools;

use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\HostImage;
use App\Domain\Pools\Actions\CreatePool;
use App\Domain\Pools\Actions\EnsureImageOnHosts;
use App\Domain\Pools\Actions\UpdatePool;
use App\Domain\Pools\Enums\DockerMode;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolRules;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Create or edit a pool. Fields validate as they change; the same rules guard the API. */
#[Layout('components.layouts.app')]
final class Form extends Component
{
    public ?string $poolId = null;

    public string $installation_id = '';

    public string $name = '';

    public string $scope = 'repository';

    public string $scope_target = '';

    /** Comma- or space-separated in the form; an array everywhere else. */
    public string $labels = 'self-hosted, linux, x64';

    public string $image = '';

    public string $cpu_limit = '';

    public string $memory_limit_mb = '';

    public int $min_idle = 0;

    public int $max_runners = 5;

    public int $idle_ttl_seconds = 900;

    public string $docker_mode = 'none';

    public string $allowed_host_labels = '';

    public bool $persist_tool_cache = false;

    public bool $confirm_public = false;

    public function mount(?string $pool = null): void
    {
        if ($pool === null) {
            $this->image = (string) config('jogger.default_runner_image');
            $this->installation_id = (string) $this->installations()->first()?->id;
            $this->syncTarget();

            // "Create pool for these labels" on the pools page.
            $wanted = request()->query('labels');
            if (is_string($wanted) && $wanted !== '' && strlen($wanted) <= 500) {
                $this->labels = implode(', ', array_filter(array_map('trim', explode(',', $wanted))));
            }

            return;
        }

        $record = RunnerPool::query()->visibleTo($this->user())->findOrFail($pool);
        $this->authorize('update', $record);

        $this->poolId = $record->id;
        $this->fill([
            'installation_id' => $record->installation_id,
            'name' => $record->name,
            'scope' => $record->scope->value,
            'scope_target' => $record->scope_target,
            'labels' => implode(', ', $record->labels),
            'image' => $record->image,
            'cpu_limit' => (string) ($record->cpu_limit ?? ''),
            'memory_limit_mb' => (string) ($record->memory_limit_mb ?? ''),
            'min_idle' => $record->min_idle,
            'max_runners' => $record->max_runners,
            'idle_ttl_seconds' => $record->idle_ttl_seconds,
            'docker_mode' => $record->docker_mode->value,
            'allowed_host_labels' => implode(', ', $record->allowed_host_labels),
            'persist_tool_cache' => $record->persist_tool_cache,
        ]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['installation_id', 'scope'], true)) {
            $this->syncTarget();
            $this->confirm_public = false;
        }

        // Live feedback for the field being edited only; cross-field checks run on save.
        $validator = Validator::make($this->input(), PoolRules::rules($this->record(), partial: $this->poolId !== null));
        $this->resetErrorBag($property);
        foreach ($validator->errors()->get($property) as $message) {
            $this->addError($property, $message);
        }
        foreach ($validator->errors()->get("{$property}.*") as $messages) {
            $this->addError($property, $messages[0]);
        }
    }

    public function save(CreatePool $create, UpdatePool $update): void
    {
        $record = $this->record();

        try {
            if ($record !== null) {
                $this->authorize('update', $record);
                $update($record, $this->input());
            } else {
                $installation = $this->installations()->firstWhere('id', $this->installation_id);
                abort_if($installation === null, 403);
                $this->authorize('createIn', [RunnerPool::class, $installation]);
                $create($this->input());
            }
        } catch (ValidationException $e) {
            // Element errors (labels.0) are shown on their field.
            $messages = [];
            foreach ($e->errors() as $key => $list) {
                $messages[explode('.', $key)[0]] ??= $list;
            }
            throw ValidationException::withMessages($messages);
        }

        $this->redirectRoute('pools.index', navigate: true);
    }

    /** Ask every eligible host to pull the image again (refreshes a moving tag, retries failures). */
    public function pullImage(EnsureImageOnHosts $ensure): void
    {
        $record = $this->record();
        abort_if($record === null, 404);
        $this->authorize('update', $record);

        $count = $ensure($record, force: true);
        $this->dispatch('toast', type: 'success', message: $count === 0 ? 'No host is available to pull the image.' : "Pull requested on {$count} host(s).");
    }

    /** True when the chosen repository is public, which needs an explicit confirmation before saving. */
    public function targetsPublicRepository(): bool
    {
        return $this->poolId === null && $this->scope === ScopeType::Repository->value
            && Repository::query()->where('installation_id', $this->installation_id)->where('full_name', $this->scope_target)->where('private', false)->exists();
    }

    public function render(): View
    {
        return view('livewire.pools.form', [
            'installations' => $this->installations(),
            'repositories' => $this->poolId === null && $this->installation_id !== ''
                ? Repository::query()->where('installation_id', $this->installation_id)->orderBy('full_name')->limit(500)->pluck('full_name')
                : collect(),
            'dockerModes' => DockerMode::cases(),
            'images' => $this->poolId === null ? collect() : HostImage::query()->with('host')
                ->where('image', $this->record()?->image)->get()->filter(fn (HostImage $i) => $i->host !== null)->sortBy('host.name')->values(),
            'publicWarning' => $this->targetsPublicRepository() ? PoolRules::publicWarning($this->scope_target) : null,
        ])->title($this->poolId ? 'Edit pool' : 'New pool');
    }

    private function syncTarget(): void
    {
        $installation = $this->installations()->firstWhere('id', $this->installation_id);
        $this->scope_target = $this->scope === ScopeType::Organization->value ? (string) $installation?->account_login : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        $list = fn (string $text) => array_values(array_filter(preg_split('/[\s,]+/', $text) ?: [], fn ($l) => $l !== ''));
        $input = [
            'name' => trim($this->name),
            'labels' => $list($this->labels),
            'image' => trim($this->image),
            'cpu_limit' => $this->cpu_limit === '' ? null : $this->cpu_limit,
            'memory_limit_mb' => $this->memory_limit_mb === '' ? null : $this->memory_limit_mb,
            'min_idle' => $this->min_idle,
            'max_runners' => $this->max_runners,
            'idle_ttl_seconds' => $this->idle_ttl_seconds,
            'docker_mode' => $this->docker_mode,
            'allowed_host_labels' => $list($this->allowed_host_labels),
            'persist_tool_cache' => $this->persist_tool_cache,
        ];

        return $this->poolId !== null ? $input : $input + [
            'installation_id' => $this->installation_id,
            'scope' => $this->scope,
            'scope_target' => trim($this->scope_target),
            'confirm_public' => $this->confirm_public,
        ];
    }

    private function record(): ?RunnerPool
    {
        return $this->poolId === null ? null : RunnerPool::query()->visibleTo($this->user())->findOrFail($this->poolId);
    }

    /**
     * Installations this user may create pools in.
     *
     * @return Collection<int, Installation>
     */
    private function installations(): Collection
    {
        $user = $this->user();

        return Installation::query()->visibleTo($user)->orderBy('account_login')->get()
            ->filter(fn (Installation $i) => $user->can('createIn', [RunnerPool::class, $i]))->values();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
