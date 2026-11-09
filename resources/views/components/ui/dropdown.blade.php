@props(['align' => 'right', 'width' => 'w-56'])

<div x-data="{ open: false }" class="relative inline-block text-left" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
    <div x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-haspopup="menu">{{ $trigger }}</div>
    <div x-show="open" x-cloak x-transition.origin.top.duration.150ms role="menu"
         class="absolute z-40 mt-2 {{ $width }} {{ $align === 'right' ? 'right-0' : 'left-0' }} overflow-hidden rounded-card border border-border bg-surface py-1 shadow-pop"
         x-on:click="open = false">
        {{ $slot }}
    </div>
</div>
