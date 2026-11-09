{{-- Segmented utilization bar: busy / provisioning / idle against max_runners. --}}
@props(['busy' => 0, 'idle' => 0, 'provisioning' => 0, 'max' => 1])

@php
    $max = max(1, (int) $max);
    $pct = fn (int $n) => min(100, round($n / $max * 100, 1));
    $live = $busy + $idle + $provisioning;
    $summary = "{$busy} busy, {$idle} idle, {$provisioning} provisioning of {$max} maximum";
@endphp

<div {{ $attributes }}>
    <div class="flex h-2 overflow-hidden rounded-full bg-border" role="img" aria-label="{{ $summary }}">
        <span class="bg-info" style="width: {{ $pct($busy) }}%"></span>
        <span class="bg-border-strong" style="width: {{ $pct($provisioning) }}%"></span>
        <span class="bg-success" style="width: {{ $pct($idle) }}%"></span>
    </div>
    <p class="mt-1.5 flex flex-wrap gap-x-3 text-[0.8125rem] text-text-secondary tabular">
        <span><span class="font-medium text-text">{{ $live }}</span> / {{ $max }} runners</span>
        <span>{{ $busy }} busy</span><span>{{ $idle }} idle</span>
        @if ($provisioning)<span>{{ $provisioning }} starting</span>@endif
    </p>
</div>
