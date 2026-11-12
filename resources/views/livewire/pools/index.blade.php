<div class="space-y-5" wire:poll.15s>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Pools</h1>
            <p class="text-text-secondary">A pool is a group of identical runners for one repository or organization. Jobs are matched to pools by their labels.</p>
        </div>
        @if ($canCreate)
            <x-ui.button icon="plus" href="{{ route('pools.create') }}" wire:navigate>New pool</x-ui.button>
        @endif
    </div>

    @if ($unmatched !== [])
        <section class="rounded-card border border-warning/40 bg-warning/5 p-5" data-testid="unmatched-jobs" aria-labelledby="unmatched-title">
            <h2 id="unmatched-title" class="flex items-center gap-2 font-semibold"><x-icon name="info" :size="18" />Jobs waiting with no matching pool</h2>
            <p class="mt-0.5 text-[0.8125rem] text-text-secondary">These queued jobs ask for labels that no active pool serves. They stay queued on GitHub until a pool matches.</p>
            <ul class="mt-3 divide-y divide-border">
                @foreach ($unmatched as $group)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="unmatched-{{ md5(implode(',', $group['labels'])) }}">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span class="mr-1 font-medium tabular">{{ $group['count'] }} {{ Str::plural('job', $group['count']) }}</span>
                            @foreach ($group['labels'] as $label)<x-ui.badge>{{ $label }}</x-ui.badge>@endforeach
                        </span>
                        @if ($canCreate)
                            <x-ui.button size="xs" variant="outline" href="{{ route('pools.create', ['labels' => implode(',', $group['labels'])]) }}" wire:navigate>Create pool for these labels</x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($pools->isEmpty())
        <x-ui.empty-state icon="cpu" title="No pools yet" description="Create a pool to start running jobs on your hosts.">
            @if ($canCreate)<x-ui.button icon="plus" href="{{ route('pools.create') }}" wire:navigate>New pool</x-ui.button>@endif
        </x-ui.empty-state>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($pools as $pool)
                <x-ui.card wire:key="pool-{{ $pool->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold">{{ $pool->name }}</h2>
                            <p class="truncate text-[0.8125rem] text-text-secondary">{{ $pool->scope_target }} · {{ $pool->scope->value }}</p>
                        </div>
                        @if ($pool->isPaused())
                            <x-ui.badge tone="warning" icon="pause-circle">Paused</x-ui.badge>
                        @else
                            <x-ui.badge tone="success" icon="check-circle">Active</x-ui.badge>
                        @endif
                    </div>

                    <x-pool-meter class="mt-4" :busy="$counts[$pool->id]['busy'] ?? 0" :idle="$counts[$pool->id]['idle'] ?? 0"
                                  :provisioning="$counts[$pool->id]['provisioning'] ?? 0" :max="$pool->max_runners" />

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @foreach ($pool->labels as $label)
                            <x-ui.badge>{{ $label }}</x-ui.badge>
                        @endforeach
                    </div>
                    <p class="mt-3 truncate font-mono text-[0.75rem] text-text-secondary" title="{{ $pool->image }}">{{ $pool->image }}</p>

                    @can('update', $pool)
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-border pt-3">
                            <x-ui.button size="xs" variant="outline" href="{{ route('pools.edit', $pool) }}" wire:navigate>Edit</x-ui.button>
                            @if ($pool->isPaused())
                                <x-ui.button size="xs" variant="outline" wire:click="resume('{{ $pool->id }}')">Resume</x-ui.button>
                            @else
                                <x-ui.button size="xs" variant="outline" wire:click="pause('{{ $pool->id }}')" wire:confirm="Pause {{ $pool->name }}? Running jobs finish, but no new runners start.">Pause</x-ui.button>
                            @endif
                        </div>
                    @endcan
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
