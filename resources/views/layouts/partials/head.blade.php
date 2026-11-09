<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
{{-- Apply the saved theme before first paint to avoid a flash. --}}
<script>
    (function () {
        try {
            var m = localStorage.getItem('theme') || 'system';
            var dark = m === 'dark' || (m === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
