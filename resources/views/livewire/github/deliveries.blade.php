@php
    $pills = [
        'received' => ['Queued', 'neutral', 'clock'],
        'processing' => ['Processing', 'info', 'spinner'],
        'processed' => ['Processed', 'success', 'check-circle'],
        'failed' => ['Failed', 'error', 'x-circle'],
        'ignored' => ['No handler', 'neutral', 'pause-circle'],
    ];
@endphp
<div class="space-y-5" wire:poll.5s>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Webhook deliveries</h1>
            <p class="text-text-secondary">Everything GitHub sent to Jogger, kept for {{ $retentionDays }} days. Replay one to run it again.</p>
        </div>
        <x-ui.button href="{{ route('github.connect') }}" variant="outline" size="sm">GitHub settings</x-ui.button>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <x-ui.select label="Event" name="event" wire:model.live="event" class="w-48">
            <option value="">All events</option>
            @foreach ($events as $e)<option value="{{ $e }}">{{ $e }}</option>@endforeach
        </x-ui.select>
        <x-ui.select label="Status" name="status" wire:model.live="status" class="w-44">
            <option value="">Any status</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $pills[$s->value][0] }}</option>@endforeach
        </x-ui.select>
    </div>

    @if ($deliveries->isEmpty())
        <x-ui.empty-state icon="terminal" title="{{ ($event || $status) ? 'No deliveries match' : 'No deliveries yet' }}"
                          description="{{ ($event || $status) ? 'Try clearing the filters.' : 'Events appear here as soon as GitHub sends them. Make sure the app is installed and APP_URL is reachable from the internet.' }}" />
    @else
        <x-ui.table>
            <x-slot:head><th>Received</th><th>Event</th><th>Repository</th><th>Status</th><th class="text-right"><span class="sr-only">Actions</span></th></x-slot:head>
            @foreach ($deliveries as $d)
                @php([$label, $tone, $icon] = $pills[$d->status->value])
                <tr wire:key="delivery-{{ $d->id }}">
                    <td data-label="Received" class="whitespace-nowrap text-text-secondary" title="{{ $d->received_at->toDateTimeString() }}">{{ $d->received_at->diffForHumans() }}</td>
                    <td data-label="Event"><span class="font-mono text-[0.8125rem]">{{ $d->event.($d->action ? '.'.$d->action : '') }}</span></td>
                    <td data-label="Repository" class="font-mono text-[0.8125rem] text-text-secondary">{{ $d->repository ?? '—' }}</td>
                    <td data-label="Status">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-ui.badge :tone="$tone">
                                @if ($icon === 'spinner')<x-icon name="spinner" :size="12" class="animate-spin motion-reduce:animate-none" />@else<x-icon :name="$icon" :size="12" />@endif
                                {{ $label }}
                            </x-ui.badge>
                            @if ($d->replay_count > 0)
                                <x-ui.badge tone="info" icon="clock" title="Replayed {{ $d->replayed_at?->diffForHumans() }}" data-testid="replayed">Replayed @if ($d->replay_count > 1)×{{ $d->replay_count }}@endif</x-ui.badge>
                            @endif
                        </div>
                        @if ($d->error)<p class="mt-1 max-w-md truncate text-[0.75rem] text-danger-ink" title="{{ $d->error }}">{{ $d->error }}</p>@endif
                    </td>
                    <td class="text-right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button size="xs" variant="outline" wire:click="view('{{ $d->id }}')">Payload</x-ui.button>
                            <x-ui.button size="xs" variant="outline" wire:click="replay('{{ $d->id }}')" wire:confirm="Replay this delivery? Its handler runs again.">Replay</x-ui.button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <div>{{ $deliveries->links() }}</div>
    @endif

    <x-ui.modal name="delivery" title="Delivery payload" max-width="max-w-3xl">
        @if ($viewing)
            <dl class="mb-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[0.8125rem]">
                <dt class="text-text-secondary">Event</dt><dd class="font-mono">{{ $viewing->event.($viewing->action ? '.'.$viewing->action : '') }}</dd>
                <dt class="text-text-secondary">Delivery ID</dt><dd class="break-all font-mono">{{ $viewing->delivery_id }}</dd>
                <dt class="text-text-secondary">Received</dt><dd>{{ $viewing->received_at->toDateTimeString() }}</dd>
            </dl>
            <pre class="max-h-[50vh] overflow-auto rounded-control border border-border bg-bg p-3 font-mono text-[0.75rem] leading-relaxed" data-testid="payload">{{ $json }}</pre>
            @if ($truncated)<p class="mt-2 text-[0.8125rem] text-text-secondary">Showing the first 200 KB. The stored payload is complete.</p>@endif
        @endif
    </x-ui.modal>
</div>
