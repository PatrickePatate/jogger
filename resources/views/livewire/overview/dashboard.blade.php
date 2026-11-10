<div class="space-y-5" wire:poll.{{ $isAdmin ? '30s' : '5s' }}>
    @if ($checklist)
        <x-ui.stepper title="Getting started" :steps="$checklist" data-testid="checklist" />
    @endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Queue depth" :value="$tiles['queued']" detail="Jobs waiting for a runner" data-testid="tile-queued" />
        <x-ui.stat label="Running jobs" :value="$tiles['running']" data-testid="tile-running" />
        <x-ui.stat label="Runners" :value="$tiles['idle'].' idle · '.$tiles['busy'].' busy'"
                   :detail="$tiles['starting'] ? $tiles['starting'].' starting' : null" data-testid="tile-runners" />
        <x-ui.stat label="Success rate (24 h)" :value="$tiles['success_rate'] === null ? '—' : $tiles['success_rate'].'%'"
                   :detail="$tiles['finished'] ? $tiles['finished'].' finished jobs' : 'No finished jobs yet'" data-testid="tile-success" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-ui.card title="Pools" description="Runners in use against each pool's maximum">
            @forelse ($pools as $pool)
                <div @class(['pt-4 mt-4 border-t border-border' => ! $loop->first]) wire:key="pool-{{ $pool['id'] }}">
                    <p class="flex items-center gap-2 text-[0.9375rem] font-medium">
                        <a href="{{ route('pools.edit', $pool['id']) }}" wire:navigate class="hover:underline">{{ $pool['name'] }}</a>
                        @if ($pool['paused'])<x-ui.badge>Paused</x-ui.badge>@endif
                    </p>
                    <x-pool-meter class="mt-2" :busy="$pool['busy']" :idle="$pool['idle']" :provisioning="$pool['provisioning']" :max="$pool['max']" />
                </div>
            @empty
                <p class="text-text-secondary">No pools yet. <a href="{{ route('pools.index') }}" wire:navigate class="underline">Create one</a> to start serving jobs.</p>
            @endforelse
        </x-ui.card>

        <div class="space-y-5">
            @if ($isAdmin)
                <x-ui.card title="Hosts">
                    @forelse ($hosts as $host)
                        <div class="flex items-center justify-between gap-3 py-1.5">
                            <span class="min-w-0 truncate font-medium">{{ $host['name'] }}</span>
                            <span class="flex shrink-0 items-center gap-3">
                                <span class="text-[0.8125rem] text-text-secondary">{{ $host['seen'] ? 'Seen '.$host['seen'] : 'Never seen' }}</span>
                                <x-host-status-pill :status="$host['status']" />
                            </span>
                        </div>
                    @empty
                        <p class="text-text-secondary">No hosts enrolled. <a href="{{ route('hosts.index') }}" wire:navigate class="underline">Enroll one</a>.</p>
                    @endforelse
                </x-ui.card>
            @endif

            <x-ui.card title="Recent failures">
                @forelse ($failures as $job)
                    <div class="flex items-center justify-between gap-3 py-1.5">
                        <span class="min-w-0">
                            <a href="{{ route('jobs.show', $job['id']) }}" wire:navigate class="block truncate font-medium hover:underline">{{ $job['name'] }}</a>
                            <span class="block truncate text-[0.8125rem] text-text-secondary">{{ $job['repository'] }}</span>
                        </span>
                        <span class="shrink-0 text-right text-[0.8125rem]">
                            <span class="block text-error">{{ $job['conclusion'] }}</span>
                            <span class="block text-text-secondary">{{ $job['when'] }}</span>
                        </span>
                    </div>
                @empty
                    <p class="text-text-secondary">Nothing has failed recently.</p>
                @endforelse
            </x-ui.card>
        </div>
    </div>

    <x-ui.card title="CI activity" description="Jobs queued per day over the last six months">
        <x-slot:actions><span class="text-2xl font-semibold tabular" data-testid="activity-total">{{ number_format($activity['total']) }}</span></x-slot:actions>
        <x-ui.activity-heatmap :days="$activity['days']" :failed="$activity['failed']" />
    </x-ui.card>
</div>
