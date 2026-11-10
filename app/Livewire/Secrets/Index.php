<?php

declare(strict_types=1);

namespace App\Livewire\Secrets;

use App\Domain\Access\Audit;
use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Secrets\Actions\ResolveSecretsForPool;
use App\Domain\Secrets\Actions\StoreSecret;
use App\Domain\Secrets\Enums\SecretKind;
use App\Domain\Secrets\Enums\SecretScope;
use App\Domain\Secrets\Models\Secret;
use App\Domain\Secrets\SecretAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Values go in and never come back out: the page lists names only, and saving an existing name rotates it. */
#[Layout('components.layouts.app')]
#[Title('Secrets')]
final class Index extends Component
{
    public string $scope = 'pool';

    public string $scopeId = '';

    public string $kind = 'env';

    public string $name = '';

    public string $username = '';

    public string $value = '';

    public function mount(): void
    {
        $this->scope = $this->user()->isAdmin() ? SecretScope::Global->value : SecretScope::Pool->value;
        $this->syncTarget();
    }

    public function updatedScope(): void
    {
        $this->syncTarget();
    }

    public function save(StoreSecret $store): void
    {
        $this->validate([
            'scope' => ['required', Rule::enum(SecretScope::class)],
            'kind' => ['required', Rule::enum(SecretKind::class)],
            'name' => ['required', 'string', 'max:100'],
            'value' => ['required', 'string'],
            'username' => [Rule::requiredIf($this->kind === SecretKind::Registry->value), 'string', 'max:200'],
        ]);
        $scope = SecretScope::from($this->scope);
        $kind = SecretKind::from($this->kind);
        $scopeId = $scope === SecretScope::Global ? null : $this->scopeId;
        abort_unless(SecretAccess::canManage($this->user(), $scope, $scopeId), 403);

        $value = $kind === SecretKind::Registry
            ? (string) json_encode(['username' => $this->username, 'password' => $this->value])
            : $this->value;
        $secret = $store($scope, $scopeId, $kind, $this->name, $value);

        // The value leaves the component's state at once, so it is not sent back to the browser.
        $this->reset('name', 'username', 'value');
        $this->dispatch('toast', type: 'success', message: $secret->rotated_at !== null && $secret->wasRecentlyCreated === false
            ? "Rotated {$secret->name}. New runners get the new value."
            : "Saved {$secret->name}.");
    }

    /** Prefills the form with an existing secret's coordinates; the value still has to be typed. */
    public function rotate(string $id): void
    {
        $secret = $this->manageable($id);
        $this->fill([
            'scope' => $secret->scope_type->value,
            'scopeId' => (string) $secret->scope_id,
            'kind' => $secret->kind->value,
            'name' => $secret->name,
            'username' => '',
            'value' => '',
        ]);
    }

    public function delete(string $id): void
    {
        $secret = $this->manageable($id);
        $secret->delete();
        Cache::forget(ResolveSecretsForPool::MASK_VERSION);
        Audit::record('secret.deleted', $secret, ['name' => $secret->name, 'scope' => $secret->scope_type->value, 'kind' => $secret->kind->value]);
        $this->dispatch('toast', type: 'success', message: "Deleted {$secret->name}. Running runners keep it until they end.");
    }

    public function render(): View
    {
        $user = $this->user();
        $installations = Installation::query()->visibleTo($user)->orderBy('account_login')->get();
        $pools = RunnerPool::query()->visibleTo($user)->orderBy('name')->get();
        $targets = [
            SecretScope::Installation->value => $installations->filter(fn (Installation $i) => SecretAccess::canManage($user, SecretScope::Installation, $i->id))->pluck('account_login', 'id'),
            SecretScope::Pool->value => $pools->filter(fn (RunnerPool $p) => $user->can('update', $p))->pluck('name', 'id'),
        ];

        $names = $installations->pluck('account_login', 'id')->union($pools->pluck('name', 'id'));
        $secrets = SecretAccess::visibleTo($user)->orderBy('scope_type')->orderBy('name')->get()
            ->map(fn (Secret $secret): array => [
                'id' => $secret->id,
                'name' => $secret->name,
                'kind' => $secret->kind,
                'scope' => $secret->scope_type,
                'target' => $secret->scope_type === SecretScope::Global ? null : ($names[$secret->scope_id] ?? 'deleted'),
                'updated' => ($secret->rotated_at ?? $secret->created_at)?->diffForHumans(),
                'rotated' => $secret->rotated_at !== null,
                'manageable' => SecretAccess::canManage($user, $secret->scope_type, $secret->scope_id),
            ]);

        return view('livewire.secrets.index', [
            'secrets' => $secrets,
            'targets' => $targets,
            'scopes' => array_values(array_filter(SecretScope::cases(), fn (SecretScope $s) => $s === SecretScope::Global ? $user->isAdmin() : $targets[$s->value]->isNotEmpty())),
            'kinds' => SecretKind::cases(),
        ]);
    }

    private function manageable(string $id): Secret
    {
        $secret = SecretAccess::visibleTo($this->user())->findOrFail($id);
        abort_unless(SecretAccess::canManage($this->user(), $secret->scope_type, $secret->scope_id), 403);

        return $secret;
    }

    /** Points the target at the first thing the user may manage in the chosen scope. */
    private function syncTarget(): void
    {
        $user = $this->user();
        $this->scopeId = (string) match (SecretScope::tryFrom($this->scope)) {
            SecretScope::Installation => $this->firstManageable(Installation::query()->visibleTo($user)->orderBy('account_login')->get(), SecretScope::Installation),
            SecretScope::Pool => $this->firstManageable(RunnerPool::query()->visibleTo($user)->orderBy('name')->get(), SecretScope::Pool),
            default => '',
        };
    }

    /**
     * @param  Collection<int, covariant \Illuminate\Database\Eloquent\Model>  $candidates
     */
    private function firstManageable(Collection $candidates, SecretScope $scope): ?string
    {
        return $candidates->first(fn ($m) => SecretAccess::canManage($this->user(), $scope, (string) $m->getKey()))?->getKey();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
