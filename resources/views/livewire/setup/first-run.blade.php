<div>
    <div class="mb-6 text-center">
        <h1 class="text-xl font-semibold">Welcome to Jogger</h1>
        <p class="mt-1 text-text-secondary">Let's get your instance ready. This takes about a minute.</p>
    </div>

    <div class="grid gap-5 md:grid-cols-[17rem_1fr] md:items-start">
        <x-ui.stepper title="Setup" :steps="$steps" />

        <x-ui.card>
            @if ($step === 1)
                <h2 class="text-base font-semibold">Create the administrator</h2>
                <p class="mt-1 text-text-secondary">This account can manage everything: GitHub, hosts, pools, users.</p>

                <form wire:submit="createAdmin" class="mt-5 space-y-4">
                    <x-ui.input label="Name" name="name" wire:model="name" autocomplete="name" autofocus :error="$errors->first('name')" />
                    <x-ui.input label="Email" name="email" type="email" wire:model="email" autocomplete="username" :error="$errors->first('email')" />
                    <x-ui.input label="Password" name="password" type="password" wire:model="password" autocomplete="new-password"
                                hint="At least 10 characters." :error="$errors->first('password')" />
                    <x-ui.input label="Confirm password" name="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" />
                    <div class="flex justify-end">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="createAdmin">Create account</x-ui.button>
                    </div>
                </form>
            @elseif ($step === 2)
                <h2 class="text-base font-semibold">Check the application URL</h2>
                <p class="mt-1 text-text-secondary">GitHub webhooks and sign-in callbacks are built from <code class="font-mono text-[0.8125rem]">APP_URL</code>.</p>

                <dl class="mt-5 space-y-3 rounded-control border border-border bg-bg p-4 text-[0.8125rem]">
                    <div class="flex justify-between gap-4"><dt class="text-text-secondary">Configured (APP_URL)</dt><dd class="font-mono">{{ $url['configured'] }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-text-secondary">You are visiting</dt><dd class="font-mono">{{ $url['browser'] }}</dd></div>
                </dl>

                @if (! $url['matches'])
                    <div class="mt-4 flex items-start gap-2 rounded-control bg-warning/10 px-3 py-2 text-[0.8125rem] text-warning" role="alert">
                        <x-icon name="warning" :size="16" class="mt-0.5" />
                        <p>These differ. Set <code class="font-mono">APP_URL={{ $url['browser'] }}</code> in your <code class="font-mono">.env</code> and restart, otherwise webhook and OAuth URLs will point to the wrong address. You can continue anyway.</p>
                    </div>
                @elseif (! $url['https'])
                    <div class="mt-4 flex items-start gap-2 rounded-control bg-warning/10 px-3 py-2 text-[0.8125rem] text-warning" role="alert">
                        <x-icon name="warning" :size="16" class="mt-0.5" />
                        <p>APP_URL is not HTTPS. GitHub only delivers webhooks to a public HTTPS address, so put Jogger behind a reverse proxy or a tunnel before connecting GitHub.</p>
                    </div>
                @else
                    <p class="mt-4 flex items-center gap-2 text-[0.8125rem] text-success"><x-icon name="check-circle" :size="16" />Looks good.</p>
                @endif

                <div class="mt-6 flex justify-end">
                    <x-ui.button wire:click="continueFromUrlCheck" icon-end="chevron-right">Continue</x-ui.button>
                </div>
            @else
                <h2 class="text-base font-semibold">Connect GitHub</h2>
                <p class="mt-1 text-text-secondary">Jogger connects to GitHub through a GitHub App to receive job events and create runners.</p>

                <div class="mt-5 rounded-control border border-border bg-bg p-4 text-[0.8125rem] text-text-secondary">
                    @if (Route::has('github.connect'))
                        <p>You can create the GitHub App in one click.</p>
                    @else
                        <p>The guided GitHub App setup arrives with the GitHub integration. You can connect later from the dashboard, nothing is lost by skipping.</p>
                    @endif
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    @if (Route::has('github.connect'))
                        <x-ui.button variant="outline" wire:click="finish">Skip for now</x-ui.button>
                        <x-ui.button href="{{ route('github.connect') }}">Connect GitHub</x-ui.button>
                    @else
                        <x-ui.button wire:click="finish">Finish setup</x-ui.button>
                    @endif
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
