<x-layouts.guest title="Confirm password">
    <h1 class="text-lg font-semibold">Confirm your password</h1>
    <p class="mt-1 text-text-secondary">This is a sensitive area. Please confirm your password to continue.</p>

    <form method="POST" action="{{ route('password.confirm.store') }}" class="mt-5 space-y-4">
        @csrf
        <x-auth-errors />
        <x-ui.input label="Password" name="password" type="password" required autofocus autocomplete="current-password" />
        <x-ui.button type="submit" class="w-full">Confirm</x-ui.button>
    </form>
</x-layouts.guest>
