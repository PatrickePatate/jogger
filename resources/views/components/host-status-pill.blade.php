@props(['status'])

@php
    $map = [
        'pending' => ['Pending', 'neutral', 'clock'],
        'online' => ['Online', 'success', 'check-circle'],
        'offline' => ['Offline', 'error', 'x-circle'],
        'cordoned' => ['Cordoned', 'neutral', 'pause-circle'],
        'draining' => ['Draining', 'warning', 'clock'],
        'revoked' => ['Revoked', 'error', 'x-circle'],
    ];
@endphp

<x-status-pill :value="$status" :map="$map" {{ $attributes }} />
