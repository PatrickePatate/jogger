<div class="space-y-5" wire:poll.15s>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Hosts</h1>
            <p class="text-text-secondary">The machines that run your runners. Each one runs the Jogger agent and connects out to this server.</p>
        </div>
        @can('admin')
            <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'enroll-host')">Add host</x-ui.button>
        @endcan
    </div>

    @if ($hosts->isEmpty())
        <x-ui.empty-state icon="server" title="No hosts yet" description="Add a host to start running jobs on your own machines.">
            @can('admin')<x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'enroll-host')">Add host</x-ui.button>@endcan
        </x-ui.empty-state>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($hosts as $host)
                @php
                    $cpuPct = $host->cpu_total ? min(100, round(($host->cpu_used ?? 0) / $host->cpu_total * 100)) : null;
                    $memPct = $host->memory_total_mb ? min(100, round(($host->memory_used_mb ?? 0) / $host->memory_total_mb * 100)) : null;
                @endphp
                <x-ui.card wire:key="host-{{ $host->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold">{{ $host->name }}</h2>
                            <p class="text-[0.8125rem] text-text-secondary">{{ $host->os ?? 'unknown OS' }} · {{ $host->arch ?? 'unknown arch' }}@if ($host->docker_version) · Docker {{ $host->docker_version }}@endif</p>
                        </div>
                        <x-host-status-pill :status="$host->status->value" />
                    </div>

                    <dl class="mt-4 space-y-3 text-[0.8125rem]">
                        @foreach ([['CPU', $cpuPct, $host->cpu_total !== null ? number_format((float) ($host->cpu_used ?? 0), 1).' / '.(float) $host->cpu_total.' cores' : 'no data'], ['Memory', $memPct, $host->memory_total_mb !== null ? round(($host->memory_used_mb ?? 0) / 1024, 1).' / '.round($host->memory_total_mb / 1024, 1).' GB' : 'no data']] as [$label, $pct, $text])
                            <div>
                                <div class="flex justify-between"><dt class="text-text-secondary">{{ $label }}</dt><dd class="tabular">{{ $text }}</dd></div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-border" role="img" aria-label="{{ $label }} {{ $pct ?? 0 }}% used">
                                    <div @class(['h-full rounded-full', 'bg-success' => ($pct ?? 0) < 70, 'bg-warning' => ($pct ?? 0) >= 70 && ($pct ?? 0) < 90, 'bg-error' => ($pct ?? 0) >= 90]) style="width: {{ $pct ?? 0 }}%"></div>
                                </div>
                            </div>
                        @endforeach
                        <div class="flex justify-between"><dt class="text-text-secondary">Disk free</dt><dd class="tabular">{{ $host->disk_free_gb !== null ? $host->disk_free_gb.' GB' : 'no data' }}</dd></div>
                    </dl>

                    <p class="mt-4 flex justify-between border-t border-border pt-3 text-[0.8125rem] text-text-secondary">
                        <span>Agent {{ $host->agent_version ?? 'unknown' }}</span>
                        <span>{{ $host->last_seen_at ? 'seen '.$host->last_seen_at->diffForHumans() : 'never seen' }}</span>
                    </p>

                    @can('admin')
                        @php $state = $host->status->value; @endphp
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if (in_array($state, ['cordoned', 'draining'], true))
                                <x-ui.button size="xs" variant="outline" wire:click="uncordon('{{ $host->id }}')">Resume</x-ui.button>
                            @else
                                <x-ui.button size="xs" variant="outline" wire:click="cordon('{{ $host->id }}')">Cordon</x-ui.button>
                                <x-ui.button size="xs" variant="outline" wire:click="drain('{{ $host->id }}')" wire:confirm="Drain {{ $host->name }}? No new runners will start there; running jobs finish first.">Drain</x-ui.button>
                            @endif
                            <x-ui.button size="xs" variant="destructive" wire:click="revoke('{{ $host->id }}')" wire:confirm="Revoke {{ $host->name }}? Its agent is disconnected for good and must be enrolled again.">Revoke</x-ui.button>
                        </div>
                    @endcan
                </x-ui.card>
            @endforeach
        </div>
    @endif

    @can('admin')<livewire:hosts.enroll-modal />@endcan
</div>
