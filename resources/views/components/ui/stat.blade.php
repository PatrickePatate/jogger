@props(['label', 'value', 'detail' => null, 'tone' => null])

<div {{ $attributes->class(['rounded-card border border-border bg-surface p-5 shadow-card']) }}>
    <p class="text-[0.8125rem] text-text-secondary">{{ $label }}</p>
    <p @class(['mt-1 text-2xl font-semibold tabular', 'text-'.$tone => $tone])>{{ $value }}</p>
    @if ($detail)<p class="mt-1 text-[0.8125rem] text-text-secondary">{{ $detail }}</p>@endif
</div>
