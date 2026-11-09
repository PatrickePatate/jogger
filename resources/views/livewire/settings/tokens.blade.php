<div class="mx-auto max-w-3xl space-y-6">
    @if ($newToken)
        <div class="rounded-card border border-success/40 bg-success/5 p-5" role="status" data-testid="new-token"
             x-data="{ copied: false }">
            <p class="flex items-center gap-2 font-semibold"><x-icon name="check-circle" :size="18" class="text-success" />Your new token</p>
            <p class="mt-1 text-[0.8125rem] text-text-secondary">Copy it now. For your security it won't be shown again.</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <code class="min-w-0 flex-1 break-all rounded-control border border-border bg-surface px-3 py-2 font-mono text-[0.8125rem]">{{ $newToken }}</code>
                <x-ui.button variant="outline" icon="check"
                             x-on:click="navigator.clipboard.writeText(@js($newToken)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                </x-ui.button>
                <x-ui.button variant="ghost" wire:click="dismissToken">Done</x-ui.button>
            </div>
        </div>
    @endif

    <x-ui.card title="Create a token" description="Tokens authenticate API calls as you, limited to the access level you pick.">
        <form wire:submit="create" class="grid gap-4 sm:grid-cols-[1fr_11rem_11rem_auto] sm:items-start">
            <x-ui.input label="Name" name="name" wire:model="name" placeholder="CI deploy script" :error="$errors->first('name')" />
            <x-ui.select label="Access" name="role" wire:model="role" :error="$errors->first('role')">
                @foreach ($roles as $r)
                    <option value="{{ $r->value }}">{{ $r->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Expires" name="expiresInDays" wire:model="expiresInDays">
                <option value="30">In 30 days</option>
                <option value="90">In 90 days</option>
                <option value="365">In 1 year</option>
                <option value="0">Never</option>
            </x-ui.select>
            <x-ui.button type="submit" class="sm:mt-[1.65rem]">Create token</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="Your tokens" :padded="false">
        @if ($tokens->isEmpty())
            <div class="p-5"><x-ui.empty-state icon="terminal" title="No tokens yet" description="Create one above to call the Jogger API from scripts and CI." /></div>
        @else
            <x-ui.table flush>
                <x-slot:head><th>Name</th><th>Access</th><th>Last used</th><th>Expires</th><th class="text-right"><span class="sr-only">Actions</span></th></x-slot:head>
                @foreach ($tokens as $token)
                    <tr wire:key="token-{{ $token->id }}">
                        <td data-label="Name" class="font-medium">{{ $token->name }}</td>
                        <td data-label="Access"><x-ui.badge :tone="$token->role->value === 'admin' ? 'warning' : 'neutral'">{{ $token->role->label() }}</x-ui.badge></td>
                        <td data-label="Last used" class="text-text-secondary">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td data-label="Expires" class="text-text-secondary">
                            @if ($token->isExpired())<x-ui.badge tone="error" icon="clock">Expired</x-ui.badge>
                            @else{{ $token->expires_at?->toFormattedDateString() ?? 'Never' }}@endif
                        </td>
                        <td class="text-right">
                            <x-ui.button size="xs" variant="destructive" wire:click="revoke('{{ $token->id }}')" wire:confirm="Revoke “{{ $token->name }}”? Anything using it will stop working.">Revoke</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
