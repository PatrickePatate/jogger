<div class="mx-auto max-w-2xl space-y-6">
    @if (session('status'))
        <p class="flex items-start gap-2 rounded-control bg-success/10 px-3 py-2 text-[0.8125rem] text-success" role="status" data-testid="flash-status"><x-icon name="check-circle" :size="16" class="mt-0.5" />{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="flex items-start gap-2 rounded-control bg-danger/10 px-3 py-2 text-[0.8125rem] text-danger-ink" role="alert" data-testid="flash-error"><x-icon name="x-circle" :size="16" class="mt-0.5" />{{ session('error') }}</p>
    @endif

    <x-ui.card title="Two-factor authentication"
               description="Protect your account with a time-based code from an authenticator app.">
        <x-slot:actions>
            @if ($enabled)
                <x-ui.badge tone="success" icon="check-circle">On</x-ui.badge>
            @elseif ($pending)
                <x-ui.badge tone="warning" icon="clock">Setup not finished</x-ui.badge>
            @else
                <x-ui.badge icon="pause-circle">Off</x-ui.badge>
            @endif
        </x-slot:actions>

        @if ($pending)
            <div class="space-y-4">
                <p>Scan this QR code with your authenticator app, then enter the 6-digit code it shows.</p>
                <div class="flex flex-wrap items-start gap-5">
                    <div class="rounded-control bg-white p-3" data-testid="two-factor-qr">{!! $qrSvg !!}</div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="text-[0.8125rem] text-text-secondary">Can't scan? Enter this key manually:</p>
                        <p class="break-all font-mono text-[0.8125rem]">{{ $secretKey }}</p>
                    </div>
                </div>
                <form wire:submit="confirmTwoFactor" class="flex flex-wrap items-start gap-3">
                    <x-ui.input label="Authentication code" name="code" wire:model="code" inputmode="numeric" autocomplete="one-time-code"
                                :error="$errors->first('code')" class="w-56" />
                    <x-ui.button type="submit" class="mt-[1.65rem]">Confirm</x-ui.button>
                </form>
            </div>
        @elseif ($enabled)
            @if ($showRecoveryCodes)
                <div class="mb-5 rounded-control border border-border bg-bg p-4">
                    <p class="font-medium">Save your recovery codes</p>
                    <p class="mt-1 text-[0.8125rem] text-text-secondary">Each code works once if you lose your device. They won't be shown again.</p>
                    <ul class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-[0.8125rem]" data-testid="recovery-codes">
                        @foreach ($recoveryCodes as $recoveryCode)
                            <li>{{ $recoveryCode }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="mb-3 text-[0.8125rem] text-text-secondary">Enter your current password to change two-factor settings.</p>
            <form class="flex flex-wrap items-start gap-3">
                <x-ui.input label="Current password" name="password" type="password" wire:model="password" autocomplete="current-password"
                            :error="$errors->first('password')" class="w-64" />
                <x-ui.button variant="outline" wire:click="regenerateRecoveryCodes" type="button" class="mt-[1.65rem]">Regenerate recovery codes</x-ui.button>
                <x-ui.button variant="destructive" class="mt-[1.65rem]" wire:click="disableTwoFactor" wire:confirm="Turn off two-factor authentication?" type="button">Turn off</x-ui.button>
            </form>
        @else
            <p class="mb-3 text-[0.8125rem] text-text-secondary">Enter your current password to turn on two-factor authentication.</p>
            <form wire:submit="enableTwoFactor" class="flex flex-wrap items-start gap-3">
                <x-ui.input label="Current password" name="password" type="password" wire:model="password" autocomplete="current-password"
                            :error="$errors->first('password')" class="w-64" />
                <x-ui.button type="submit" class="mt-[1.65rem]">Turn on</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    @if ($githubAvailable)
        <x-ui.card title="GitHub" description="Sign in with your GitHub account instead of typing your password.">
            <x-slot:actions>
                <x-ui.badge :tone="$githubLinked ? 'success' : 'neutral'" :icon="$githubLinked ? 'check-circle' : 'pause-circle'" data-testid="github-badge">{{ $githubLinked ? 'Linked'.($githubLogin ? ' · @'.$githubLogin : '') : 'Not linked' }}</x-ui.badge>
            </x-slot:actions>

            @if ($githubLinked && ! $hasPassword)
                <p class="rounded-control border border-border bg-bg p-3 text-[0.8125rem] text-text-secondary" data-testid="github-no-password">
                    This account signs in with GitHub only. To be able to unlink it, first set a password with “Forgot password” on the sign-in page.
                </p>
                <div class="mt-3"><x-ui.button variant="outline" disabled>Unlink GitHub</x-ui.button></div>
            @else
                <p class="mb-3 text-[0.8125rem] text-text-secondary">Enter your current password to {{ $githubLinked ? 'unlink' : 'link' }} your GitHub account.</p>
                <form class="flex flex-wrap items-start gap-3">
                    <x-ui.input label="Current password" name="githubPassword" type="password" wire:model="githubPassword" autocomplete="current-password" :error="$errors->first('githubPassword')" class="w-64" />
                    @if ($githubLinked)
                        <x-ui.button variant="destructive" type="button" class="mt-[1.65rem]" wire:click="unlinkGithub" wire:confirm="Unlink your GitHub account?">Unlink GitHub</x-ui.button>
                    @else
                        <x-ui.button type="button" class="mt-[1.65rem]" icon="terminal" wire:click="linkGithub" data-testid="link-github">Link GitHub</x-ui.button>
                    @endif
                </form>
            @endif
        </x-ui.card>
    @endif
</div>
