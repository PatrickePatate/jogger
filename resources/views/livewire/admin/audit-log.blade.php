<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Audit log</h1>
            <p class="text-text-secondary">Every change made by a person or an API token.</p>
        </div>
        <x-ui.button variant="outline" wire:click="export">Export CSV</x-ui.button>
    </div>
    <div class="grid gap-3 sm:grid-cols-4">
        <x-ui.select label="Actor" name="actor" wire:model.live="actor">
            <option value="">Anyone</option>
            @foreach ($actors as $id => $email)<option value="{{ $id }}">{{ $email }}</option>@endforeach
        </x-ui.select>
        <x-ui.select label="Action" name="action" wire:model.live="action">
            <option value="">Any action</option>
            @foreach ($actions as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
        </x-ui.select>
        <x-ui.input label="From" type="date" name="from" wire:model.live="from" />
        <x-ui.input label="To" type="date" name="to" wire:model.live="to" />
    </div>
    @if ($entries->isEmpty())
        <x-ui.empty-state icon="list" title="No entries" description="Nothing matches these filters." />
    @else
        <x-ui.table>
            <x-slot:head><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th></x-slot:head>
            @foreach ($entries as $entry)
                <tr wire:key="audit-{{ $entry->id }}">
                    <td data-label="Time" class="whitespace-nowrap tabular text-text-secondary">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</td>
                    <td data-label="Actor">{{ $entry->user->email ?? 'System' }}</td>
                    <td data-label="Action" class="font-mono text-[0.8125rem]">{{ $entry->action }}</td>
                    <td data-label="Target" class="text-text-secondary">{{ $entry->target_type ?? '—' }}</td>
                    <td data-label="Details" class="max-w-md truncate font-mono text-[0.75rem] text-text-secondary" title="{{ json_encode($entry->metadata) }}">{{ $entry->metadata ? json_encode($entry->metadata) : '' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        {{ $entries->links() }}
    @endif
</div>
