<x-layouts.guest title="Two-factor authentication">
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <h1 class="text-lg font-semibold">Two-factor authentication</h1>
        <p class="mt-1 text-text-secondary" x-show="!recovery">Enter the 6-digit code from your authenticator app.</p>
        <p class="mt-1 text-text-secondary" x-show="recovery" x-cloak>Enter one of your emergency recovery codes. Each works once.</p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-5 space-y-4">
            @csrf
            <x-auth-errors />
            <div x-show="!recovery">
                <x-ui.input label="Authentication code" name="code" inputmode="numeric" autocomplete="one-time-code" x-bind:disabled="recovery" autofocus />
            </div>
            <div x-show="recovery" x-cloak>
                <x-ui.input label="Recovery code" name="recovery_code" autocomplete="off" x-bind:disabled="!recovery" />
            </div>
            <div class="flex items-center justify-between gap-3">
                <button type="button" class="text-[0.8125rem] text-text-secondary underline-offset-4 hover:text-text hover:underline" x-on:click="recovery = !recovery"
                        x-text="recovery ? 'Use an authentication code' : 'Use a recovery code'"></button>
                <x-ui.button type="submit">Verify</x-ui.button>
            </div>
        </form>
    </div>
</x-layouts.guest>
