<x-layouts.guest title="Choose a new password">
    <h1 class="text-lg font-semibold">Choose a new password</h1>

    <form method="POST" action="{{ route('password.update') }}" class="mt-5 space-y-4">
        @csrf
        <x-auth-errors />
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-ui.input label="Email" name="email" type="email" :value="old('email', $request->query('email'))" required autocomplete="username" />
        <x-ui.input label="New password" name="password" type="password" required autocomplete="new-password" />
        <x-ui.input label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />
        <x-ui.button type="submit" class="w-full">Reset password</x-ui.button>
    </form>
</x-layouts.guest>
