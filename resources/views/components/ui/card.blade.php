@props(['title' => null, 'description' => null, 'padded' => true])

<section {{ $attributes->class(['rounded-card border border-border bg-surface shadow-card']) }}>
    @if ($title || isset($actions))
        <header class="flex items-start justify-between gap-4 px-5 pt-5">
            <div>
                <h2 class="text-base font-semibold leading-snug">{{ $title }}</h2>
                @if ($description)<p class="mt-0.5 text-[0.8125rem] text-text-secondary">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['p-5' => $padded, 'pt-4' => $padded && ($title || isset($actions)), 'mt-4' => ! $padded && ($title || isset($actions))])>{{ $slot }}</div>
    @isset($footer)
        <footer class="border-t border-border px-5 py-3">{{ $footer }}</footer>
    @endisset
</section>
