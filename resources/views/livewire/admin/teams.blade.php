<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Teams</h1>
            <p class="text-text-secondary">Teams group people and decide which installations and pools they can see.</p>
        </div>
        <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'new-team')">New team</x-ui.button>
    </div>

    <div class="grid gap-5 lg:grid-cols-[16rem_1fr] lg:items-start">
        <nav aria-label="Teams" class="space-y-1">
            @forelse ($teams as $t)
                <button type="button" wire:key="team-{{ $t->id }}" wire:click="selectTeam('{{ $t->id }}')"
                        @class(['flex w-full items-center justify-between rounded-control px-3 py-2 text-left transition-colors', 'bg-ink/5 font-medium' => $team?->is($t), 'text-text-secondary hover:bg-ink/5 hover:text-text' => ! $team?->is($t)])
                        @if ($team?->is($t)) aria-current="true" @endif>
                    <span class="truncate">{{ $t->name }}</span>
                    <span class="text-[0.8125rem] tabular">{{ $t->members_count }}</span>
                </button>
            @empty
                <x-ui.empty-state icon="server" title="No teams yet" description="Create a team to give people access to specific pools." />
            @endforelse
        </nav>

        <div>
            @if ($team)
                <x-ui.card :title="$team->name" description="Members and what this team can access." wire:key="team-detail-{{ $team->id }}">
                    <x-slot:actions>
                        <x-ui.button size="xs" variant="destructive" wire:click="deleteTeam" wire:confirm="Delete “{{ $team->name }}”? Its members lose the access it granted.">Delete team</x-ui.button>
                    </x-slot:actions>

                    <h3 class="text-[0.8125rem] font-medium text-text-secondary">Members</h3>
                    <div class="mt-2 divide-y divide-border rounded-control border border-border">
                        @forelse ($members as $member)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-2" wire:key="member-{{ $member->id }}">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $member->name }}</p>
                                    <p class="truncate text-[0.8125rem] text-text-secondary">{{ $member->email }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select aria-label="Role for {{ $member->name }}" class="h-8 rounded-control border border-border-strong bg-surface px-2 text-[0.8125rem]"
                                            wire:change="changeRole('{{ $member->id }}', $event.target.value)">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->value }}" @selected($member->pivot->role === $role->value)>{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button size="xs" variant="ghost" wire:click="removeMember('{{ $member->id }}')" wire:confirm="Remove {{ $member->name }} from this team?">Remove</x-ui.button>
                                </div>
                            </div>
                        @empty
                            <p class="px-3 py-4 text-text-secondary">No members yet.</p>
                        @endforelse
                    </div>

                    @if ($candidates->isNotEmpty())
                        <form wire:submit="addMember" class="mt-4 flex flex-wrap items-start gap-3">
                            <x-ui.select label="Add a member" name="newMemberId" wire:model="newMemberId" :error="$errors->first('newMemberId')" class="w-64">
                                <option value="">Choose a user…</option>
                                @foreach ($candidates as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->email }})</option>@endforeach
                            </x-ui.select>
                            <x-ui.select label="Role" name="newMemberRole" wire:model="newMemberRole" class="w-40">
                                @foreach ($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach
                            </x-ui.select>
                            <x-ui.button type="submit" class="mt-[1.65rem]">Add</x-ui.button>
                        </form>
                    @endif

                    <h3 class="mt-6 text-[0.8125rem] font-medium text-text-secondary">Access granted</h3>
                    <div class="mt-2">
                        @forelse ($grants as $grant)
                            <p class="flex items-center gap-2 py-1" wire:key="grant-{{ $grant->id }}"><x-ui.badge>{{ \Illuminate\Support\Str::headline($grant->grantable_type) }}</x-ui.badge><span class="font-mono text-[0.8125rem] text-text-secondary">{{ $grant->grantable_id }}</span></p>
                        @empty
                            <p class="text-text-secondary">No installations or pools yet. Once GitHub is connected and pools exist, you'll grant them to this team here.</p>
                        @endforelse
                    </div>
                </x-ui.card>
            @elseif ($teams->isNotEmpty())
                <x-ui.empty-state icon="server" title="Select a team" description="Pick a team on the left to manage its members." />
            @endif
        </div>
    </div>

    <x-ui.modal name="new-team" title="New team" max-width="max-w-md">
        <form wire:submit="createTeam" class="space-y-4">
            <x-ui.input label="Team name" name="newTeamName" wire:model="newTeamName" placeholder="Platform engineering" :error="$errors->first('newTeamName')" />
            <div class="flex justify-end gap-2">
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal', 'new-team')">Cancel</x-ui.button>
                <x-ui.button type="submit">Create team</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
