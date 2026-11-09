@props(['tone' => 'neutral', 'dot' => false, 'icon' => null])

@php
    $tones = [
        'neutral' => 'bg-text-secondary/10 text-text-secondary',
        'success' => 'bg-success/10 text-success',
        'error' => 'bg-error/10 text-error',
        'warning' => 'bg-warning/10 text-warning',
        'info' => 'bg-info/10 text-info',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium', $tones[$tone] ?? $tones['neutral']]) }}>
    @if ($icon)<x-icon :name="$icon" :size="12" />@elseif ($dot)<span class="size-1.5 rounded-full bg-current"></span>@endif
    {{ $slot }}
</span>
