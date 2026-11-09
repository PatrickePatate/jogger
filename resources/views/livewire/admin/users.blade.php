<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Users</h1>
            <p class="text-text-secondary">Invite people and manage who can administer Jogger.</p>
        </div>
        <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'invite')">Invite user</x-ui.button>
    </div>

    @if ($invitations->isNotEmpty())
        <x-ui.card title="Pending invitations" :padded="false">
            <x-ui.table flush>
                <x-slot:head><th>Email</th><th>Access</th><th>Invited by</th><th>Expires</th><th class="text-right"><span class="sr-only">Actions</span></th></x-slot:head>
                @foreach ($invitations as $invitation)
                    <tr wire:key="invitation-{{ $invitation->id }}">
                        <td data-label="Email" class="font-medium">{{ $invitation->email }}</td>
                        <td data-label="Access">
                            @if ($invitation->is_admin)<x-ui.badge tone="warning">Administrator</x-ui.badge>
                            @elseif ($invitation->team){{ $invitation->team->name }} · {{ $invitation->role?->label() }}
                            @else<span class="text-text-secondary">No team yet</span>@endif
                        </td>
                        <td data-label="Invited by" class="text-text-secondary">{{ $invitation->inviter?->name ?? '—' }}</td>
                        <td data-label="Expires" class="text-text-secondary">
                            @if ($invitation->expires_at->isPast())<x-ui.badge tone="error" icon="clock">Expired</x-ui.badge>@else{{ $invitation->expires_at->diffForHumans() }}@endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                <x-ui.button size="xs" variant="outline" wire:click="resend('{{ $invitation->id }}')">Resend</x-ui.button>
                                <x-ui.button size="xs" variant="ghost" wire:click="revokeInvitation('{{ $invitation->id }}')" wire:confirm="Revoke the invitation for {{ $invitation->email }}?">Revoke</x-ui.button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <div class="w-64"><x-ui.input name="search" wire:model.live.debounce.300ms="search" placeholder="Search name or email" aria-label="Search users" /></div>
            <x-ui.toggle label="Show deactivated" wire:model.live="deactivated" />
        </div>

        @if ($users->isEmpty())
            <x-ui.empty-state icon="search" title="{{ $deactivated ? 'No deactivated users' : 'No users found' }}" description="{{ $search ? 'Try a different search.' : '' }}" />
        @else
            <x-ui.table>
                <x-slot:head><th>User</th><th>Access</th><th class="text-right"><span class="sr-only">Actions</span></th></x-slot:head>
                @foreach ($users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td data-label="User">
                            <p class="font-medium">{{ $user->name }}@if ($user->is($me)) <x-ui.badge class="ml-1">You</x-ui.badge>@endif</p>
                            <p class="text-[0.8125rem] text-text-secondary">{{ $user->email }}</p>
                        </td>
                        <td data-label="Access">
                            <div class="flex flex-wrap gap-1.5">
                                @if ($user->isAdmin())<x-ui.badge tone="warning" icon="check-circle">Administrator</x-ui.badge>@endif
                                @forelse ($user->teams as $team)
                                    <x-ui.badge>{{ $team->name }} · {{ ucfirst($team->pivot->role) }}</x-ui.badge>
                                @empty
                                    @unless ($user->isAdmin())<span class="text-text-secondary">No teams</span>@endunless
                                @endforelse
                            </div>
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                @if ($user->trashed())
                                    <x-ui.button size="xs" variant="outline" wire:click="reactivate('{{ $user->id }}')">Reactivate</x-ui.button>
                                @else
                                    @if ($user->isAdmin())
                                        <x-ui.button size="xs" variant="outline" wire:click="setAdministrator('{{ $user->id }}', false)" wire:confirm="Remove administrator access from {{ $user->name }}?">Remove admin</x-ui.button>
                                    @else
                                        <x-ui.button size="xs" variant="outline" wire:click="setAdministrator('{{ $user->id }}', true)" wire:confirm="Make {{ $user->name }} an administrator? They will have full access.">Make admin</x-ui.button>
                                    @endif
                                    @unless ($user->is($me))
                                        <x-ui.button size="xs" variant="destructive" wire:click="deactivate('{{ $user->id }}')" wire:confirm="Deactivate {{ $user->name }}? They are signed out and their API tokens are revoked.">Deactivate</x-ui.button>
                                    @endunless
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            <div>{{ $users->links() }}</div>
        @endif
    </div>

    <x-ui.modal name="invite" title="Invite a user">
        <form wire:submit="invite" class="space-y-4">
            <x-ui.input label="Email" name="inviteEmail" type="email" wire:model="inviteEmail" autocomplete="off" :error="$errors->first('inviteEmail')" />
            <x-ui.checkbox label="Administrator" hint="Full access to everything, including users and GitHub settings." wire:model.live="inviteAdministrator" />
            @unless ($inviteAdministrator)
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select label="Team" name="inviteTeamId" wire:model="inviteTeamId" :error="$errors->first('inviteTeamId')">
                        <option value="">No team yet</option>
                        @foreach ($teams as $team)<option value="{{ $team->id }}">{{ $team->name }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.select label="Role in team" name="inviteRole" wire:model="inviteRole">
                        @foreach ($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach
                    </x-ui.select>
                </div>
            @endunless
            <p class="text-[0.8125rem] text-text-secondary">They receive an email with a link, valid for {{ \App\Domain\Access\Models\Invitation::VALID_DAYS }} days, to choose a password.</p>
            <div class="flex justify-end gap-2">
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal', 'invite')">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="invite">Send invitation</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
