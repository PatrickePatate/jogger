@props(['status', 'conclusion' => null])

@php
    $map = [
        'queued' => ['Queued', 'warning', 'clock'],
        'in_progress' => ['Running', 'info', 'spinner'],
        'success' => ['Succeeded', 'success', 'check-circle'],
        'failure' => ['Failed', 'error', 'x-circle'],
        'timed_out' => ['Timed out', 'error', 'clock'],
        'cancelled' => ['Cancelled', 'neutral', 'pause-circle'],
        'skipped' => ['Skipped', 'neutral', 'pause-circle'],
        'unmatched' => ['No matching pool', 'warning', 'warning'],
    ];
    $value = $status === 'completed' ? ($conclusion ?? 'success') : $status;
@endphp

<x-status-pill :value="$value" :map="$map" {{ $attributes }} />
