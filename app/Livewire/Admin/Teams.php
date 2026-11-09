<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Access\Actions\AddTeamMember;
use App\Domain\Access\Actions\ChangeRole;
use App\Domain\Access\Actions\CreateTeam;
use App\Domain\Access\Actions\DeleteTeam;
use App\Domain\Access\Actions\RemoveTeamMember;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Teams')]
final class Teams extends Component
{
    #[Url(as: 'team')]
    public string $selectedTeamId = '';

    public string $newTeamName = '';

    public string $newMemberId = '';

    public string $newMemberRole = 'viewer';

    public function createTeam(CreateTeam $create): void
    {
        Gate::authorize('admin');

        $this->validate(['newTeamName' => ['required', 'string', 'max:120']]);

        $team = $create($this->admin(), $this->newTeamName);
        $this->selectedTeamId = $team->id;
        $this->reset('newTeamName');
        $this->dispatch('close-modal', name: 'new-team');
        $this->dispatch('toast', type: 'success', message: "Team “{$team->name}” created.");
    }

    public function selectTeam(string $teamId): void
    {
        $this->selectedTeamId = Team::query()->findOrFail($teamId)->id;
        $this->reset('newMemberId');
    }

    public function deleteTeam(DeleteTeam $delete): void
    {
        Gate::authorize('admin');

        $delete($this->admin(), $this->team());
        $this->selectedTeamId = '';
        $this->dispatch('toast', type: 'success', message: 'Team deleted.');
    }

    public function addMember(AddTeamMember $add): void
    {
        Gate::authorize('admin');

        $this->validate([
            'newMemberId' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'newMemberRole' => ['required', Rule::enum(Role::class)],
        ]);

        $add($this->admin(), $this->team(), User::query()->findOrFail($this->newMemberId), Role::from($this->newMemberRole));
        $this->reset('newMemberId');
        $this->newMemberRole = 'viewer';
    }

    public function changeRole(string $userId, string $role, ChangeRole $change): void
    {
        Gate::authorize('admin');

        $change($this->admin(), $this->team(), User::query()->findOrFail($userId), Role::from($role));
        $this->dispatch('toast', type: 'success', message: 'Role updated. It applies on their next request.');
    }

    public function removeMember(string $userId, RemoveTeamMember $remove): void
    {
        Gate::authorize('admin');

        $remove($this->admin(), $this->team(), User::query()->findOrFail($userId));
    }

    public function render(): View
    {
        $teams = Team::query()->withCount('members')->orderBy('name')->get();
        $team = $this->selectedTeamId !== '' ? Team::query()->find($this->selectedTeamId) : null;

        return view('livewire.admin.teams', [
            'teams' => $teams,
            'team' => $team,
            'members' => $team?->members()->orderBy('name')->get() ?? collect(),
            'grants' => $team?->grants()->orderBy('grantable_type')->get() ?? collect(),
            'candidates' => $team
                ? User::query()->whereNotIn('id', DB::table('team_user')->where('team_id', $team->id)->select('user_id'))->orderBy('name')->get()
                : collect(),
            'roles' => Role::cases(),
        ]);
    }

    private function team(): Team
    {
        return Team::query()->findOrFail($this->selectedTeamId);
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
