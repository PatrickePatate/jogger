@props(['state'])

@php
    $map = [
        'provisioning' => ['Provisioning', 'info', 'spinner'],
        'idle' => ['Idle', 'success', 'check-circle'],
        'busy' => ['Busy', 'info', 'terminal'],
        'terminating' => ['Terminating', 'warning', 'clock'],
        'gone' => ['Gone', 'neutral', 'pause-circle'],
        'failed' => ['Failed', 'error', 'x-circle'],
    ];
@endphp

<x-status-pill :value="$state" :map="$map" {{ $attributes }} />
