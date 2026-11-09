@props(['name', 'title' => null, 'maxWidth' => 'max-w-lg'])

<div x-data="{ open: false }"
     x-on:open-modal.window="if ((typeof $event.detail === 'string' ? $event.detail : $event.detail?.name) === '{{ $name }}') open = true"
     x-on:close-modal.window="if ((typeof $event.detail === 'string' ? $event.detail : $event.detail?.name) === '{{ $name }}') open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true"
     @if($title) aria-labelledby="modal-{{ $name }}-title" @endif>
    <div class="absolute inset-0 bg-ink/40 backdrop-blur-[1px]" x-show="open" x-transition.opacity.duration.200ms x-on:click="open = false"></div>
    <div x-show="open" x-trap.noscroll.inert="open" x-transition.duration.200ms
         class="relative w-full {{ $maxWidth }} rounded-[20px] border border-border bg-surface p-6 shadow-pop">
        <div class="mb-4 flex items-start justify-between gap-4">
            @if ($title)<h2 id="modal-{{ $name }}-title" class="text-base font-semibold">{{ $title }}</h2>@endif
            <button type="button" x-on:click="open = false" class="-m-1 rounded-md p-1 text-text-secondary hover:bg-ink/5" aria-label="Close dialog"><x-icon name="x" :size="18" /></button>
        </div>
        {{ $slot }}
        @isset($footer)<div class="mt-6 flex justify-end gap-2">{{ $footer }}</div>@endisset
    </div>
</div>
