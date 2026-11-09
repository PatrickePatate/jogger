<x-layouts.guest title="Accept invitation">
    <h1 class="text-lg font-semibold">Join {{ config('app.name') }}</h1>
    <p class="mt-1 text-text-secondary">
        Set a password for <span class="font-medium text-text">{{ $invitation->email }}</span>.
        @if ($invitation->is_admin)You'll be an administrator.
        @elseif ($invitation->team)You'll join <span class="font-medium text-text">{{ $invitation->team->name }}</span> as {{ $invitation->role?->label() }}.@endif
    </p>

    <form method="POST" action="{{ request()->fullUrl() }}" class="mt-5 space-y-4">
        @csrf
        <x-auth-errors />
        <x-ui.input label="Your name" name="name" :value="old('name')" required autofocus autocomplete="name" />
        <x-ui.input label="Password" name="password" type="password" required autocomplete="new-password" hint="At least 10 characters." />
        <x-ui.input label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />
        <x-ui.button type="submit" class="w-full">Create account</x-ui.button>
    </form>
</x-layouts.guest>
