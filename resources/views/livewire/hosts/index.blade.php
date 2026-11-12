@php use App\Domain\Cloud\Enums\CloudHostState; @endphp
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

                    @if ($host->ephemeral || $host->cloud_state)
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-[0.8125rem]">
                            @if ($host->ephemeral)<x-ui.badge tone="info" icon="clock">Ephemeral</x-ui.badge>@endif
                            @if ($host->template)<span class="text-text-secondary">Template <span class="font-medium text-text">{{ $host->template->name }}</span></span>@endif
                            @if ($host->cloud_state)
                                @php $cloudTone = match ($host->cloud_state) { CloudHostState::Online => 'success', CloudHostState::Failed => 'error', CloudHostState::Draining, CloudHostState::Deleting => 'warning', default => 'neutral' }; @endphp
                                <span class="text-text-secondary">Cloud state</span> <x-ui.badge :tone="$cloudTone">{{ $host->cloud_state->label() }}</x-ui.badge>
                            @endif
                        </div>
                    @endif

                    <dl class="mt-4 space-y-3 text-[0.8125rem]">
                        @foreach ([['CPU', $cpuPct, $host->cpu_total !== null ? number_format((float) ($host->cpu_used ?? 0), 1).' / '.(float) $host->cpu_total.' cores' : 'no data'], ['Memory', $memPct, $host->memory_total_mb !== null ? round(($host->memory_used_mb ?? 0) / 1024, 1).' / '.round($host->memory_total_mb / 1024, 1).' GB' : 'no data']] as [$label, $pct, $text])
                            {{-- A dl group may only hold dt/dd, so the meter lives in a second dd. --}}
                            <div class="flex flex-wrap justify-between">
                                <dt class="text-text-secondary">{{ $label }}</dt><dd class="tabular">{{ $text }}</dd>
                                <dd class="mt-1 w-full">
                                    <div class="h-1.5 overflow-hidden rounded-full bg-border" role="img" aria-label="{{ $label }} {{ $pct ?? 0 }}% used">
                                        <div @class(['h-full rounded-full', 'bg-success' => ($pct ?? 0) < 70, 'bg-warning' => ($pct ?? 0) >= 70 && ($pct ?? 0) < 90, 'bg-error' => ($pct ?? 0) >= 90]) style="width: {{ $pct ?? 0 }}%"></div>
                                    </div>
                                </dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between"><dt class="text-text-secondary">Disk free</dt><dd class="tabular">{{ $host->disk_free_gb !== null ? $host->disk_free_gb.' GB' : 'no data' }}</dd></div>
                    </dl>

                    @php
                        $c = $counts[$host->id] ?? [];
                        $busy = $c['busy'] ?? 0; $idle = $c['idle'] ?? 0; $starting = $c['provisioning'] ?? 0; $stopping = $c['terminating'] ?? 0;
                        $present = $busy + $idle + $starting + $stopping;
                    @endphp
                    <div class="mt-4 border-t border-border pt-3 text-[0.8125rem]" data-testid="host-runners-{{ $host->name }}">
                        <div class="flex items-center justify-between gap-3">
                            <span class="tabular"><span class="font-medium">{{ $present }}</span> {{ Str::plural('runner', $present) }}
                                <span class="text-text-secondary">· {{ $busy }} busy · {{ $idle }} idle @if ($starting)· {{ $starting }} starting @endif @if ($stopping)· {{ $stopping }} stopping @endif</span></span>
                            @if ($present)
                                <button type="button" class="font-medium underline" wire:click="select('{{ $host->id }}')" aria-expanded="{{ $selected === $host->id ? 'true' : 'false' }}">{{ $selected === $host->id ? 'Hide' : 'Show' }}</button>
                            @endif
                        </div>
                        @if ($present)
                            <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-border" aria-hidden="true">
                                <span class="bg-info" style="width: {{ $busy / $present * 100 }}%"></span>
                                <span class="bg-border-strong" style="width: {{ ($starting + $stopping) / $present * 100 }}%"></span>
                                <span class="bg-success" style="width: {{ $idle / $present * 100 }}%"></span>
                            </div>
                        @endif
                        @if ($selected === $host->id)
                            <ul class="mt-3 space-y-2">
                                @foreach ($runners as $runner)
                                    <li class="flex items-center justify-between gap-3" wire:key="host-runner-{{ $runner->id }}">
                                        <span class="min-w-0">
                                            <span class="block truncate font-mono text-[0.75rem]">{{ $runner->name }}</span>
                                            <span class="block truncate text-text-secondary">{{ $runner->pool->name }}@if ($runner->job) · <a href="{{ route('jobs.show', $runner->job) }}" wire:navigate class="hover:underline">{{ $runner->job->job_name }}</a>@endif</span>
                                        </span>
                                        <x-runner-state-pill :state="$runner->state->value" />
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

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
