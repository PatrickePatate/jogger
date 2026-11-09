<x-layouts.guest title="Reset password">
    <h1 class="text-lg font-semibold">Forgot your password?</h1>
    <p class="mt-1 text-text-secondary">Enter your email and we will send you a reset link.</p>

    @if (session('status'))
        <p class="mt-4 rounded-control bg-success/10 px-3 py-2 text-[0.8125rem] text-success" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-5 space-y-4">
        @csrf
        <x-auth-errors />
        <x-ui.input label="Email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('login') }}" class="text-[0.8125rem] text-text-secondary underline-offset-4 hover:text-text hover:underline">Back to sign in</a>
            <x-ui.button type="submit">Send reset link</x-ui.button>
        </div>
    </form>
</x-layouts.guest>
