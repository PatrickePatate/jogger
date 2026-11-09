@props(['title', 'description' => null, 'icon' => 'info'])

<div {{ $attributes->class(['flex flex-col items-center gap-3 rounded-card border border-dashed border-border-strong px-6 py-12 text-center']) }}>
    <span class="grid size-11 place-items-center rounded-full bg-ink/5 text-text-secondary"><x-icon :name="$icon" :size="22" /></span>
    <div>
        <h3 class="font-semibold">{{ $title }}</h3>
        @if ($description)<p class="mt-1 max-w-sm text-text-secondary">{{ $description }}</p>@endif
    </div>
    @if ($slot->isNotEmpty())<div class="mt-1">{{ $slot }}</div>@endif
</div>
