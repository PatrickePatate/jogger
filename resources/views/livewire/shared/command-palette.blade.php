<div x-data="{
        open: false,
        active: 0,
        items() { return [...this.$refs.list.querySelectorAll('[data-result]')] },
        show() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.input.focus()) },
        move(by) { const n = this.items().length; if (n) { this.active = (this.active + by + n) % n; this.items()[this.active].scrollIntoView({ block: 'nearest' }) } },
        go() { this.items()[this.active]?.click() },
     }"
     x-on:keydown.window="if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); open ? open = false : show() }"
     x-on:open-command-palette.window="show()"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="Command palette">
    <div class="absolute inset-0 bg-ink/40 backdrop-blur-[1px]" x-on:click="open = false"></div>
    <div x-trap.noscroll.inert="open" class="relative w-full max-w-xl overflow-hidden rounded-[20px] border border-border bg-surface shadow-pop">
        <div class="flex items-center gap-3 border-b border-border px-4">
            <x-icon name="list" :size="18" class="text-text-secondary" />
            <input x-ref="input" type="text" wire:model.live.debounce.300ms="query" placeholder="Go to a page, or search jobs, repositories, pools…"
                   class="h-12 w-full border-0 bg-transparent p-0 outline-none focus:ring-0" autocomplete="off" spellcheck="false"
                   role="combobox" aria-expanded="true" aria-controls="command-palette-results" aria-label="Search"
                   x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="go()"
                   x-on:input="active = 0">
            <kbd class="shrink-0 rounded border border-border px-1.5 text-[0.75rem] text-text-secondary">Esc</kbd>
        </div>
        <ul x-ref="list" id="command-palette-results" role="listbox" class="max-h-[50vh] overflow-y-auto p-2">
            @php($group = null)
            @forelse ($results as $i => $result)
                @if ($result['group'] !== $group)
                    @php($group = $result['group'])
                    <li role="presentation" class="px-3 pb-1 pt-2 text-[0.75rem] font-medium uppercase tracking-wide text-text-secondary">{{ $group }}</li>
                @endif
                <li role="presentation" wire:key="result-{{ $i }}-{{ md5($result['url']) }}">
                    <a href="{{ $result['url'] }}" wire:navigate data-result role="option" x-on:click="open = false"
                       x-bind:aria-selected="active === {{ $i }}" x-on:mousemove="active = {{ $i }}"
                       class="flex items-baseline justify-between gap-3 rounded-control px-3 py-2"
                       x-bind:class="active === {{ $i }} ? 'bg-ink/5' : ''">
                        <span class="min-w-0 truncate font-medium">{{ $result['label'] }}</span>
                        @if ($result['detail'])<span class="min-w-0 shrink truncate text-[0.8125rem] text-text-secondary">{{ $result['detail'] }}</span>@endif
                    </a>
                </li>
            @empty
                <li class="px-3 py-6 text-center text-text-secondary">Nothing matches “{{ $query }}”.</li>
            @endforelse
        </ul>
    </div>
</div>
