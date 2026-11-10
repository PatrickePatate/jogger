@php
    $col = fn (string $key) => $series->map(fn ($r) => $r->{$key} === null ? null : (float) $r->{$key})->all();
    $fmt = fn ($bucket) => $bucket ? \Illuminate\Support\Carbon::parse($bucket)->format($range === '1h' ? 'H:i' : 'M j, H:i') : null;
@endphp
<div class="space-y-5" wire:poll.60s>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Insights</h1>
            <p class="text-text-secondary">How long jobs wait, how busy runners are, and where the minutes go.</p>
        </div>
        <x-ui.select name="range" wire:model.live="range" class="w-44" aria-label="Period">
            @foreach ($ranges as $key => [$label])
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Jobs started" :value="number_format($totals['started'])" data-testid="insight-started" />
        <x-ui.stat label="Success rate" :value="$totals['success_rate'] === null ? '—' : $totals['success_rate'].'%'" :detail="$totals['finished'].' finished'" />
        <x-ui.stat label="Queue wait" :value="$totals['p50']" :detail="'typical · worst p95 '.$totals['p95']" data-testid="insight-wait" />
        <x-ui.stat label="Runner minutes" :value="number_format($totals['minutes'])" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-ui.card title="Queue depth" description="Jobs waiting for a runner">
            <x-ui.line-chart :series="[['label' => 'Queued jobs', 'values' => $col('queue_depth'), 'tone' => 'warning']]" :from="$fmt($labels[0])" :to="$fmt($labels[1])" />
        </x-ui.card>
        <x-ui.card title="Queue wait" description="Seconds between queued and started">
            <x-ui.line-chart unit=" s" :from="$fmt($labels[0])" :to="$fmt($labels[1])" :series="[
                ['label' => 'p50', 'values' => array_map(fn ($v) => $v === null ? null : $v / 1000, $col('p50')), 'tone' => 'info'],
                ['label' => 'p95', 'values' => array_map(fn ($v) => $v === null ? null : $v / 1000, $col('p95')), 'tone' => 'error'],
            ]" />
        </x-ui.card>
        <x-ui.card title="Runners" description="Busy and idle runners">
            <x-ui.line-chart :from="$fmt($labels[0])" :to="$fmt($labels[1])" :series="[
                ['label' => 'Busy', 'values' => $col('busy'), 'tone' => 'info'],
                ['label' => 'Idle', 'values' => $col('idle'), 'tone' => 'success'],
            ]" />
        </x-ui.card>
        <x-ui.card title="Jobs" description="Succeeded and failed per {{ $range === '1h' ? 'minute' : 'hour' }}">
            <x-ui.line-chart :from="$fmt($labels[0])" :to="$fmt($labels[1])" :series="[
                ['label' => 'Succeeded', 'values' => $col('jobs_succeeded'), 'tone' => 'success'],
                ['label' => 'Failed', 'values' => $col('jobs_failed'), 'tone' => 'error'],
            ]" />
        </x-ui.card>
    </div>

    <x-ui.card title="Repositories" description="Sorted by runner minutes" :padded="false">
        <x-slot:actions><x-ui.button size="xs" variant="outline" wire:click="export">Export CSV</x-ui.button></x-slot:actions>
        @if ($repositories === [])
            <p class="p-5 text-text-secondary">No jobs in this period.</p>
        @else
            <x-ui.table flush>
                <x-slot:head><th>Repository</th><th class="text-right">Started</th><th class="text-right">Succeeded</th><th class="text-right">Failed</th><th class="text-right">Success</th><th class="text-right">Minutes</th></x-slot:head>
                @foreach ($repositories as $row)
                    <tr wire:key="repo-{{ $row['name'] }}">
                        <td data-label="Repository">{{ $row['name'] }}</td>
                        <td data-label="Started" class="text-right tabular">{{ $row['started'] }}</td>
                        <td data-label="Succeeded" class="text-right tabular">{{ $row['succeeded'] }}</td>
                        <td data-label="Failed" class="text-right tabular">{{ $row['failed'] }}</td>
                        <td data-label="Success" class="text-right tabular">{{ $row['success_rate'] === null ? '—' : $row['success_rate'].'%' }}</td>
                        <td data-label="Minutes" class="text-right tabular">{{ $row['minutes'] }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-ui.card title="Pools" :padded="false">
            @if ($pools === [])
                <p class="p-5 text-text-secondary">No jobs in this period.</p>
            @else
                <x-ui.table flush>
                    <x-slot:head><th>Pool</th><th class="text-right">Jobs</th><th class="text-right">Success</th><th class="text-right">Minutes</th></x-slot:head>
                    @foreach ($pools as $row)
                        <tr wire:key="pool-{{ $row['name'] }}">
                            <td data-label="Pool">{{ $row['name'] }}</td>
                            <td data-label="Jobs" class="text-right tabular">{{ $row['started'] }}</td>
                            <td data-label="Success" class="text-right tabular">{{ $row['success_rate'] === null ? '—' : $row['success_rate'].'%' }}</td>
                            <td data-label="Minutes" class="text-right tabular">{{ $row['minutes'] }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="Jobs per day" :padded="false">
            @if ($days === [])
                <p class="p-5 text-text-secondary">No jobs in this period.</p>
            @else
                <x-ui.table flush>
                    <x-slot:head><th>Day</th><th class="text-right">Finished</th><th class="text-right">Success</th></x-slot:head>
                    @foreach (array_reverse($days) as $day)
                        <tr wire:key="day-{{ $day['day'] }}">
                            <td data-label="Day" class="tabular">{{ $day['day'] }}</td>
                            <td data-label="Finished" class="text-right tabular">{{ $day['finished'] }}</td>
                            <td data-label="Success" class="text-right tabular">{{ $day['success_rate'] === null ? '—' : $day['success_rate'].'%' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>

    @if ($hosts->isNotEmpty())
        <x-ui.card title="Host capacity" description="As last reported by each agent" :padded="false">
            <x-ui.table flush>
                <x-slot:head><th>Host</th><th>Status</th><th class="text-right">CPU</th><th class="text-right">Memory</th><th class="text-right">Disk free</th></x-slot:head>
                @foreach ($hosts as $host)
                    <tr wire:key="host-{{ $host->id }}">
                        <td data-label="Host">{{ $host->name }}</td>
                        <td data-label="Status"><x-host-status-pill :status="$host->status->value" /></td>
                        <td data-label="CPU" class="text-right tabular">{{ $host->cpu_total !== null ? number_format((float) ($host->cpu_used ?? 0), 1).' / '.(float) $host->cpu_total : '—' }}</td>
                        <td data-label="Memory" class="text-right tabular">{{ $host->memory_total_mb !== null ? round(($host->memory_used_mb ?? 0) / 1024, 1).' / '.round($host->memory_total_mb / 1024, 1).' GB' : '—' }}</td>
                        <td data-label="Disk free" class="text-right tabular">{{ $host->disk_free_gb !== null ? $host->disk_free_gb.' GB' : '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
