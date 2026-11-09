@props(['name', 'size' => 20])

@php
    $paths = [
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6L17 7M7 17l-1.4 1.4"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>',
        'monitor' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'warning' => '<path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4M12 17h.01"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pause-circle' => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
        'spinner' => '<path d="M12 3a9 9 0 1 0 9 9"/>',
        'home' => '<path d="M4 11l8-7 8 7v8a1 1 0 0 1-1 1h-4v-6h-6v6H5a1 1 0 0 1-1-1z"/>',
        'list' => '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
        'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'cpu' => '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3M13 15h4"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];
    $body = $paths[$name] ?? $paths['info'];
@endphp

<svg xmlns="http://www.w3.org/2000/svg" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" {{ $attributes->class(['shrink-0']) }}>{!! $body !!}</svg>
