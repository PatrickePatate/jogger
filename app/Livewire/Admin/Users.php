<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Access\Actions\DeactivateUser;
use App\Domain\Access\Actions\InviteUser;
use App\Domain\Access\Actions\ReactivateUser;
use App\Domain\Access\Actions\RevokeInvitation;
use App\Domain\Access\Actions\SetAdministrator;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Invitation;
use App\Domain\Access\Models\Team;
use App\Models\User;
use App\Support\Exceptions\JoggerException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Users')]
final class Users extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $deactivated = false;

    public string $inviteEmail = '';

    public bool $inviteAdministrator = false;

    public string $inviteTeamId = '';

    public string $inviteRole = 'viewer';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDeactivated(): void
    {
        $this->resetPage();
    }

    public function invite(InviteUser $invite): void
    {
        Gate::authorize('admin');

        $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:255'],
            'inviteAdministrator' => ['boolean'],
            'inviteTeamId' => ['nullable', Rule::exists('teams', 'id')->whereNull('deleted_at')],
            'inviteRole' => ['required', Rule::enum(Role::class)],
        ]);

        $team = $this->inviteTeamId !== '' && ! $this->inviteAdministrator ? Team::query()->find($this->inviteTeamId) : null;

        $this->attempt(function () use ($invite, $team): void {
            $invite($this->admin(), $this->inviteEmail, $this->inviteAdministrator, $team, $team ? Role::from($this->inviteRole) : null);

            $this->toast('success', "Invitation sent to {$this->inviteEmail}.");
            $this->reset('inviteEmail', 'inviteAdministrator', 'inviteTeamId');
            $this->inviteRole = 'viewer';
            $this->dispatch('close-modal', name: 'invite');
        });
    }

    public function resend(string $invitationId, InviteUser $invite): void
    {
        Gate::authorize('admin');
        $invitation = Invitation::pending()->findOrFail($invitationId);

        $this->attempt(function () use ($invite, $invitation): void {
            $invite($this->admin(), $invitation->email, $invitation->is_admin, $invitation->team, $invitation->role);
            $this->toast('success', "Invitation re-sent to {$invitation->email}.");
        });
    }

    public function revokeInvitation(string $invitationId, RevokeInvitation $revoke): void
    {
        Gate::authorize('admin');

        $revoke($this->admin(), Invitation::pending()->findOrFail($invitationId));
        $this->toast('success', 'Invitation revoked.');
    }

    public function setAdministrator(string $userId, bool $administrator, SetAdministrator $set): void
    {
        Gate::authorize('admin');

        $this->attempt(function () use ($userId, $administrator, $set): void {
            $set($this->admin(), User::query()->findOrFail($userId), $administrator);
            $this->toast('success', $administrator ? 'Administrator access granted.' : 'Administrator access removed.');
        });
    }

    public function deactivate(string $userId, DeactivateUser $deactivate): void
    {
        Gate::authorize('admin');

        $this->attempt(function () use ($userId, $deactivate): void {
            $deactivate($this->admin(), User::query()->findOrFail($userId));
            $this->toast('success', 'User deactivated.');
        });
    }

    public function reactivate(string $userId, ReactivateUser $reactivate): void
    {
        Gate::authorize('admin');

        $reactivate($this->admin(), User::onlyTrashed()->findOrFail($userId));
        $this->toast('success', 'User reactivated.');
    }

    public function render(): View
    {
        $users = User::query()
            ->when($this->deactivated, fn ($q) => $q->onlyTrashed())
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.$this->search.'%')
                ->orWhere('email', 'ilike', '%'.$this->search.'%')))
            ->with('teams')
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.users', [
            'users' => $users,
            'invitations' => Invitation::pending()->with(['team', 'inviter'])->latest()->get(),
            'teams' => Team::query()->orderBy('name')->get(),
            'roles' => Role::cases(),
            'me' => $this->admin(),
        ]);
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function toast(string $type, string $message): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }

    /** Runs an action, turning expected domain refusals into error toasts. */
    private function attempt(\Closure $action): void
    {
        try {
            $action();
        } catch (JoggerException|AuthorizationException $e) {
            $this->toast('error', $e->getMessage());
        }
    }
}
