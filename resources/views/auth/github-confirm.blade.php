<x-layouts.guest title="Link GitHub">
    <h1 class="text-lg font-semibold">Link your GitHub account</h1>
    <p class="mt-1 text-text-secondary">
        GitHub account <span class="font-medium text-text">{{ '@'.$githubLogin }}</span> has a verified email that matches the Jogger account
        <span class="font-medium text-text">{{ $email }}</span>. Confirm it's you with that account's password to link them.
    </p>

    <form method="POST" action="{{ route('auth.github.confirm.store') }}" class="mt-5 space-y-4">
        @csrf
        <x-auth-errors />
        <x-ui.input label="Jogger password" name="password" type="password" required autofocus autocomplete="current-password" />
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('login') }}" class="text-[0.8125rem] text-text-secondary underline-offset-4 hover:text-text hover:underline">Cancel</a>
            <x-ui.button type="submit">Link and sign in</x-ui.button>
        </div>
    </form>
</x-layouts.guest>
