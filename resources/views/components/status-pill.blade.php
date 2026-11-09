{{-- Generic pill: $map is [value => [label, tone, icon]]. Unknown values render neutral with the raw value. --}}
@props(['value', 'map'])

@php
    $key = (string) $value;
    [$label, $tone, $icon] = $map[$key] ?? [\Illuminate\Support\Str::headline($key), 'neutral', 'info'];
@endphp

<x-ui.badge :tone="$tone" {{ $attributes }}>
    @if ($icon === 'spinner')
        <x-icon name="spinner" :size="12" class="animate-spin motion-reduce:animate-none" />
    @else
        <x-icon :name="$icon" :size="12" />
    @endif
    {{ $label }}
</x-ui.badge>
