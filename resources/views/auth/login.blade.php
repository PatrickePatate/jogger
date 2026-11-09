<x-layouts.guest title="Sign in">
    <h1 class="text-lg font-semibold">Sign in</h1>
    <p class="mt-1 text-text-secondary">Use your Jogger account.</p>

    @if (session('error'))
        <p class="mt-4 flex items-start gap-2 rounded-control bg-danger/10 px-3 py-2 text-[0.8125rem] text-danger-ink" role="alert" data-testid="login-error"><x-icon name="x-circle" :size="16" class="mt-0.5" />{{ session('error') }}</p>
    @endif

    @if (session('status'))
        <p class="mt-4 rounded-control bg-success/10 px-3 py-2 text-[0.8125rem] text-success" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-5 space-y-4">
        @csrf
        <x-auth-errors />
        <x-ui.input label="Email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />
        <x-ui.input label="Password" name="password" type="password" required autocomplete="current-password" />
        <div class="flex items-center justify-between">
            <x-ui.checkbox name="remember" label="Remember me" />
            <a href="{{ route('password.request') }}" class="text-[0.8125rem] text-text-secondary underline-offset-4 hover:text-text hover:underline">Forgot password?</a>
        </div>
        <x-ui.button type="submit" class="w-full">Sign in</x-ui.button>
    </form>

    @if (\App\Domain\Github\Models\GithubApp::query()->exists())
        <div class="my-5 flex items-center gap-3 text-[0.75rem] text-text-secondary"><span class="h-px flex-1 bg-border"></span>or<span class="h-px flex-1 bg-border"></span></div>
        <x-ui.button href="{{ route('auth.github.redirect') }}" variant="outline" class="w-full" icon="terminal" data-testid="github-signin">Continue with GitHub</x-ui.button>
    @endif
</x-layouts.guest>
