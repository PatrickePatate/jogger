<div x-data="{ mode: joggerTheme.get() }" class="inline-flex rounded-control border border-border bg-surface p-0.5" role="group" aria-label="Theme">
    @foreach (['light' => 'sun', 'system' => 'monitor', 'dark' => 'moon'] as $mode => $icon)
        <button type="button" x-on:click="mode = '{{ $mode }}'; joggerTheme.set('{{ $mode }}')"
                x-bind:aria-pressed="(mode === '{{ $mode }}').toString()"
                x-bind:class="mode === '{{ $mode }}' ? 'bg-ink text-surface' : 'text-text-secondary hover:text-text'"
                class="grid size-7 place-items-center rounded-[8px] transition-colors" aria-label="{{ ucfirst($mode) }} theme">
            <x-icon :name="$icon" :size="15" />
        </button>
    @endforeach
</div>
