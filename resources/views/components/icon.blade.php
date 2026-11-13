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
        'layout-dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'list-checks' => '<path d="M3 6l1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17M11 6h10M11 12h10M11 18h10"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 12.5l9 5 9-5M3 17l9 5 9-5"/>',
        'box' => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
        'bar-chart' => '<path d="M4 20h16"/><path d="M7 20v-7M12 20V5M17 20v-10"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 8l3 3M14 6l3 3"/>',
        'git-branch' => '<circle cx="6" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="8" r="2"/><path d="M6 8v8M18 10c0 5-8 3-12 6"/>',
        'plug' => '<path d="M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-12 0zM12 17v4"/>',
        'inbox' => '<path d="M3 13l3-8h12l3 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.4c2.3.6 3.5 2.4 3.5 5.6"/>',
        'building' => '<rect x="5" y="3" width="14" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/><path d="M10 21v-3h4v3"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h3"/>',
        'sliders' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'cloud' => '<path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 1 0 5.3 15 4 4 0 0 0 6 19z"/>',
        'shapes' => '<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/><circle cx="17" cy="7" r="4"/><path d="M7 14l-4 7h8z"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2.5 2.5L15.5 10"/>',
        'bell' => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
        'log-out' => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4M16 8l4 4-4 4M20 12H9"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];
    $body = $paths[$name] ?? $paths['info'];
@endphp

<svg xmlns="http://www.w3.org/2000/svg" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" {{ $attributes->class(['shrink-0']) }}>{!! $body !!}</svg>
