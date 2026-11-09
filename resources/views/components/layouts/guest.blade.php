@props(['wide' => false, 'title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>@include('layouts.partials.head')</head>
<body>
    <main @class(['mx-auto grid min-h-screen w-full content-center gap-6 px-4 py-10', 'max-w-md' => ! $wide, 'max-w-3xl' => $wide])>
        <div class="flex items-center justify-center gap-2 text-xl font-semibold">
            <span class="grid size-8 place-items-center rounded-lg bg-ink text-surface"><x-icon name="terminal" :size="18" /></span>
            jogger
        </div>
        @if ($wide){{ $slot }}@else<x-ui.card>{{ $slot }}</x-ui.card>@endif
        <div class="flex justify-center"><x-theme-toggle /></div>
    </main>
    <x-ui.toast />
    @livewireScripts
</body>
</html>
