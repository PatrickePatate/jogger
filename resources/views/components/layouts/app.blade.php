@php
    // Items appear automatically once their route exists.
    $nav = collect([
        ['route' => 'overview', 'label' => 'Overview', 'icon' => 'home'],
        ['route' => 'jobs.index', 'label' => 'Jobs', 'icon' => 'list'],
        ['route' => 'pools.index', 'label' => 'Pools', 'icon' => 'cpu'],
        ['route' => 'runners.index', 'label' => 'Runners', 'icon' => 'terminal'],
        ['route' => 'hosts.index', 'label' => 'Hosts', 'icon' => 'server'],
        ['route' => 'github.connect', 'label' => 'GitHub', 'icon' => 'terminal', 'admin' => true],
        ['route' => 'github.installations', 'label' => 'Installations', 'icon' => 'server', 'admin' => true],
        ['route' => 'github.deliveries', 'label' => 'Deliveries', 'icon' => 'list', 'admin' => true],
        ['route' => 'admin.users', 'label' => 'Users', 'icon' => 'cpu', 'admin' => true],
        ['route' => 'admin.teams', 'label' => 'Teams', 'icon' => 'server', 'admin' => true],
        ['route' => 'dev.ui', 'label' => 'UI kit', 'icon' => 'info', 'dev' => true],
    ])->filter(fn ($i) => Route::has($i['route'])
        && (! ($i['dev'] ?? false) || app()->isLocal())
        && (! ($i['admin'] ?? false) || auth()->user()?->isAdmin()));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>@include('layouts.partials.head')</head>
<body x-data="{ nav: false }" x-on:keydown.escape.window="nav = false">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-control focus:bg-ink focus:px-3 focus:py-2 focus:text-surface">Skip to content</a>

    <div class="flex min-h-screen">
        {{-- Mobile backdrop --}}
        <div x-show="nav" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-ink/40 lg:hidden" x-on:click="nav = false"></div>

        <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-border bg-surface transition-transform lg:static lg:translate-x-0"
               x-bind:class="{ 'translate-x-0': nav }" aria-label="Main navigation">
            <div class="flex h-14 items-center gap-2 px-5 text-base font-semibold">
                <span class="grid size-7 place-items-center rounded-lg bg-ink text-surface"><x-icon name="terminal" :size="16" /></span>
                jogger
            </div>
            <nav class="flex-1 space-y-0.5 px-3 py-2">
                @foreach ($nav as $item)
                    @php($active = request()->routeIs($item['route']) || (str_ends_with($item['route'], '.index') && request()->routeIs(Str::before($item['route'], '.').'.*')))
                    <a href="{{ route($item['route']) }}" @if($active) aria-current="page" @endif
                       @class(['flex items-center gap-3 rounded-control px-3 py-2 font-medium transition-colors', 'bg-ink/5 text-text' => $active, 'text-text-secondary hover:bg-ink/5 hover:text-text' => ! $active])>
                        <x-icon :name="$item['icon']" :size="18" />{{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="border-t border-border p-3"><x-theme-toggle /></div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-border bg-surface/90 px-4 backdrop-blur sm:px-8">
                <button type="button" class="-ml-1 rounded-md p-1.5 text-text-secondary hover:bg-ink/5 lg:hidden" x-on:click="nav = true" aria-label="Open navigation">
                    <x-icon name="menu" />
                </button>
                <div class="min-w-0 flex-1 truncate font-semibold">{{ $title ?? '' }}</div>
                @isset($actions){{ $actions }}@endisset
                @auth
                    <x-ui.dropdown>
                        <x-slot:trigger>
                            <button type="button" class="flex items-center gap-2 rounded-control px-2 py-1 hover:bg-ink/5">
                                <span class="grid size-7 place-items-center rounded-full bg-ink text-xs font-semibold text-surface">{{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}</span>
                                <x-icon name="chevron-down" :size="14" />
                            </button>
                        </x-slot:trigger>
                        <div class="px-3 py-2 text-[0.8125rem] text-text-secondary">{{ auth()->user()->email }}</div>
                        <x-ui.dropdown-item href="{{ route('settings.security') }}" icon="check-circle">Security</x-ui.dropdown-item>
                        <x-ui.dropdown-item href="{{ route('settings.tokens') }}" icon="terminal">API tokens</x-ui.dropdown-item>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-ui.dropdown-item type="submit" icon="x">Sign out</x-ui.dropdown-item>
                        </form>
                    </x-ui.dropdown>
                @endauth
            </header>

            <main id="main" class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-8">{{ $slot }}</main>
        </div>
    </div>

    <x-ui.toast />
    @stack('scripts')
    @livewireScripts
</body>
</html>
