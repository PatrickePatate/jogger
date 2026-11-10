<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Notifications</h1>
        <p class="text-text-secondary">Jogger raises an alert when a host goes offline or runs low on disk, a pool cannot start runners, GitHub rejects its credentials, or webhooks fail. Alerts always show under the bell; add e-mail addresses or webhooks to be told elsewhere.</p>
    </div>

    @if ($newSecret)
        <div class="rounded-card border border-success/40 bg-success/5 p-5" role="status" data-testid="new-secret">
            <p class="font-semibold">Webhook signing secret</p>
            <p class="mt-1 text-[0.8125rem] text-text-secondary">Each request carries <code class="font-mono">X-Jogger-Signature-256: sha256=…</code>, the HMAC-SHA256 of the body with this secret. It won't be shown again.</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <code class="min-w-0 flex-1 break-all rounded-control border border-border bg-surface px-3 py-2 font-mono text-[0.8125rem]">{{ $newSecret }}</code>
                <x-ui.button variant="ghost" wire:click="dismissSecret">Done</x-ui.button>
            </div>
        </div>
    @endif

    <x-ui.card title="Active alerts">
        @forelse ($firing as $alert)
            <div class="py-2" wire:key="alert-{{ $alert->id }}">
                <p class="font-medium text-error">{{ $alert->title }}</p>
                <p class="text-[0.8125rem] text-text-secondary">{{ $alert->body }} · since {{ $alert->fired_at->diffForHumans() }}</p>
            </div>
        @empty
            <p class="text-text-secondary">No active alerts.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Send alerts to">
        <form wire:submit="add" class="grid gap-4 sm:grid-cols-[9rem_1fr_14rem_auto] sm:items-start">
            <x-ui.select label="Channel" name="channel" wire:model.live="channel">
                <option value="mail">E-mail</option>
                <option value="webhook">Webhook</option>
            </x-ui.select>
            <x-ui.input :label="$channel === 'mail' ? 'E-mail address' : 'URL'" name="target" wire:model="target"
                        :placeholder="$channel === 'mail' ? 'oncall@example.com' : 'https://example.com/hooks/jogger'" :error="$errors->first('target')" />
            <x-ui.select label="Alerts" name="alertType" wire:model="alertType" :error="$errors->first('alertType')">
                <option value="">All alerts</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit" class="sm:mt-[1.65rem]">Add</x-ui.button>
        </form>

        @if ($rules->isNotEmpty())
            <ul class="mt-5 divide-y divide-border border-t border-border">
                @foreach ($rules as $rule)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="rule-{{ $rule->id }}">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $rule->target }}</p>
                            <p class="text-[0.8125rem] text-text-secondary">
                                {{ $rule->channel === 'mail' ? 'E-mail' : 'Webhook' }} · {{ $rule->alert_type ? \App\Domain\Notifications\Alerts\AlertType::from($rule->alert_type)->label() : 'All alerts' }}
                                @if ($rule->last_sent_at) · last sent {{ $rule->last_sent_at->diffForHumans() }}@endif
                            </p>
                            @if ($rule->last_error)
                                <p class="text-[0.8125rem] text-error">Last attempt failed {{ $rule->last_error_at?->diffForHumans() }}: {{ $rule->last_error }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-ui.button size="xs" variant="outline" wire:click="toggle('{{ $rule->id }}')">{{ $rule->enabled ? 'Pause' : 'Resume' }}</x-ui.button>
                            <x-ui.button size="xs" variant="destructive" wire:click="delete('{{ $rule->id }}')" wire:confirm="Stop sending alerts to {{ $rule->target }}?">Remove</x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
