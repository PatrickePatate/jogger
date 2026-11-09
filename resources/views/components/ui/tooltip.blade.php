@props(['text'])

<span x-data="{ show: false }" class="relative inline-flex" x-on:mouseenter="show = true" x-on:mouseleave="show = false"
      x-on:focusin="show = true" x-on:focusout="show = false" x-on:keydown.escape="show = false">
    {{ $slot }}
    <span x-show="show" x-cloak role="tooltip" x-transition.opacity.duration.100ms
          class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-md bg-ink px-2 py-1 text-xs text-surface shadow-pop">{{ $text }}</span>
</span>
