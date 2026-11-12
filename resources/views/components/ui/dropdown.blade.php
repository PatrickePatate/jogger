@props(['align' => 'right', 'width' => 'w-56'])

<div x-data="{ open: false }" class="relative inline-block text-left" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
    {{-- The ARIA state belongs on the trigger control itself, not on this wrapper (a div may not carry aria-expanded). --}}
    <div x-on:click="open = !open" x-init="$el.querySelector('button, a')?.setAttribute('aria-haspopup', 'menu')"
         x-effect="$el.querySelector('button, a')?.setAttribute('aria-expanded', open.toString())">{{ $trigger }}</div>
    <div x-show="open" x-cloak x-transition.origin.top.duration.150ms role="menu"
         class="absolute z-40 mt-2 {{ $width }} {{ $align === 'right' ? 'right-0' : 'left-0' }} overflow-hidden rounded-card border border-border bg-surface py-1 shadow-pop"
         x-on:click="open = false">
        {{ $slot }}
    </div>
</div>
