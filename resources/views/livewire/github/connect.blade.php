<div class="space-y-5" @if ($app && $installations->isEmpty()) wire:poll.5s @endif>
    <div>
        <h1 class="text-xl font-semibold">GitHub</h1>
        <p class="text-text-secondary">Jogger connects through a GitHub App to hear about jobs and create runners.</p>
    </div>

    @if (session('status'))
        <p class="flex items-start gap-2 rounded-control bg-success/10 px-3 py-2 text-[0.8125rem] text-success" role="status"><x-icon name="check-circle" :size="16" class="mt-0.5" />{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="flex items-start gap-2 rounded-control bg-danger/10 px-3 py-2 text-[0.8125rem] text-danger-ink" role="alert" data-testid="flash-error"><x-icon name="x-circle" :size="16" class="mt-0.5" />{{ session('error') }}</p>
    @endif

    @unless ($reachability->ok())
        <div class="flex items-start gap-2 rounded-control bg-warning/10 px-3 py-2 text-[0.8125rem] text-warning" role="alert" data-testid="reachability-warning">
            <x-icon name="warning" :size="16" class="mt-0.5" />
            <p>{{ $reachability->warning() }}</p>
        </div>
    @endunless

    <div class="grid gap-5 md:grid-cols-[17rem_1fr] md:items-start">
        <x-ui.stepper title="Connect GitHub" :steps="$steps" />

        <div class="space-y-5">
            @if (! $app)
                <x-ui.card title="Create the GitHub App" description="Jogger needs its own GitHub App. GitHub will show you exactly what it asks for before anything is created.">
                    <x-ui.tabs :tabs="['manifest' => 'Create it for me', 'manual' => 'I already have an app']">
                        <x-ui.tab-panel name="manifest">
                            <form method="POST" action="{{ route('github.manifest.redirect') }}" class="space-y-4"
                                  x-data="{ type: 'personal' }">
                                @csrf
                                <fieldset class="space-y-2">
                                    <legend class="text-[0.8125rem] font-medium">Create the app on</legend>
                                    <label class="flex items-center gap-2"><input type="radio" name="account_type" value="personal" x-model="type" class="accent-ink"> My personal GitHub account</label>
                                    <label class="flex items-center gap-2"><input type="radio" name="account_type" value="organization" x-model="type" class="accent-ink"> A GitHub organization</label>
                                </fieldset>
                                <div x-show="type === 'organization'" x-cloak>
                                    <x-ui.input label="Organization login" name="organization" placeholder="octo-org" x-bind:required="type === 'organization'" :error="$errors->first('organization')" />
                                </div>
                                <x-ui.input label="App name" name="app_name" placeholder="Jogger {{ \Illuminate\Support\Str::before((string) parse_url(config('app.url'), PHP_URL_HOST), '.') }}"
                                            hint="Optional. App names are unique across GitHub; you can change it on GitHub's page." maxlength="34" />
                                <div class="rounded-control border border-border bg-bg p-3 text-[0.8125rem] text-text-secondary">
                                    <p class="font-medium text-text">What GitHub will ask you to approve</p>
                                    <ul class="mt-1.5 list-disc space-y-0.5 pl-5">
                                        <li>Repositories: <strong>Administration</strong> (write) to create and remove runners, <strong>Actions</strong> and <strong>Metadata</strong> (read)</li>
                                        <li>Organization: <strong>Self-hosted runners</strong> (write)</li>
                                        <li>Events: <code class="font-mono">workflow_job</code>, <code class="font-mono">repository</code></li>
                                    </ul>
                                </div>
                                <x-ui.button type="submit" icon-end="chevron-right">Create on GitHub</x-ui.button>
                            </form>
                        </x-ui.tab-panel>

                        <x-ui.tab-panel name="manual">
                            <form wire:submit="connectManually" class="space-y-4">
                                @error('manual')<p class="flex items-start gap-2 rounded-control bg-danger/10 px-3 py-2 text-[0.8125rem] text-danger-ink" role="alert"><x-icon name="x-circle" :size="16" class="mt-0.5" />{{ $message }}</p>@enderror
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-ui.input label="App ID" name="appId" wire:model="appId" inputmode="numeric" :error="$errors->first('appId')" />
                                    <x-ui.input label="Client ID" name="clientId" wire:model="clientId" placeholder="Iv1…" :error="$errors->first('clientId')" />
                                    <x-ui.input label="Client secret" name="clientSecret" type="password" wire:model="clientSecret" autocomplete="off" :error="$errors->first('clientSecret')" />
                                    <x-ui.input label="Webhook secret" name="webhookSecret" type="password" wire:model="webhookSecret" autocomplete="off" :error="$errors->first('webhookSecret')" />
                                </div>
                                <x-ui.textarea label="Private key (.pem)" name="privateKey" wire:model="privateKey" rows="6" placeholder="-----BEGIN RSA PRIVATE KEY-----" class="font-mono" :error="$errors->first('privateKey')" />
                                <div class="rounded-control border border-border bg-bg p-3 text-[0.8125rem] text-text-secondary">
                                    Set the app's webhook URL to <code class="font-mono">{{ $webhookUrl }}</code> and give it the permissions listed under “Create it for me”. Jogger checks the key with GitHub before saving anything.
                                </div>
                                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="connectManually">Verify and connect</x-ui.button>
                            </form>
                        </x-ui.tab-panel>
                    </x-ui.tabs>
                </x-ui.card>
            @else
                <x-ui.card title="GitHub App" description="Connected">
                    <x-slot:actions><x-ui.badge tone="success" icon="check-circle">Connected</x-ui.badge></x-slot:actions>
                    <dl class="space-y-2.5 text-[0.8125rem]">
                        <div class="flex justify-between gap-4"><dt class="text-text-secondary">App</dt><dd><a href="{{ $appUrl }}" target="_blank" rel="noopener" class="font-medium underline-offset-4 hover:underline">{{ $app->slug }}</a></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-text-secondary">App ID</dt><dd class="font-mono">{{ $app->app_id }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-text-secondary">Webhook URL</dt><dd class="break-all font-mono">{{ $webhookUrl }}</dd></div>
                    </dl>
                    <p class="mt-3 rounded-control border border-border bg-bg p-3 text-[0.8125rem] text-text-secondary" data-testid="email-permission-hint">
                        <span class="font-medium text-text">Sign in with GitHub:</span> to let people be matched to their Jogger account by email, enable the
                        <strong>Email addresses</strong> account permission (read-only) in the app's
                        <a href="{{ $appUrl }}" target="_blank" rel="noopener" class="underline-offset-4 hover:underline">GitHub settings</a> → Permissions &amp; events. Linking from Settings → Security works without it.
                    </p>
                    <x-slot:footer><a href="{{ route('github.deliveries') }}" class="text-[0.8125rem] font-medium underline-offset-4 hover:underline">View webhook deliveries →</a></x-slot:footer>
                </x-ui.card>

                <x-ui.card title="Installations" :padded="false">
                    @if ($installations->isEmpty())
                        <div class="space-y-4 p-5">
                            <p class="text-text-secondary">Install the app on the account (or organization) whose repositories should use Jogger runners. Choose the repositories on GitHub; they will appear here automatically.</p>
                            <x-ui.button href="{{ $installUrl }}" target="_blank" rel="noopener" icon-end="chevron-right">Install on GitHub</x-ui.button>
                            <p class="flex items-center gap-2 text-[0.8125rem] text-text-secondary"><x-icon name="spinner" :size="14" class="animate-spin motion-reduce:animate-none" />Waiting for GitHub to report the installation…</p>
                        </div>
                    @else
                        <x-ui.table flush>
                            <x-slot:head><th>Account</th><th>Type</th><th>Status</th><th>Repositories</th></x-slot:head>
                            @foreach ($installations as $installation)
                                <tr wire:key="inst-{{ $installation->id }}">
                                    <td data-label="Account" class="font-medium">{{ $installation->account_login }}</td>
                                    <td data-label="Type" class="text-text-secondary">{{ $installation->account_type === \App\Domain\Github\Enums\AccountType::Organization ? 'Organization' : 'User' }}</td>
                                    <td data-label="Status"><x-ui.badge :tone="$installation->isActive() ? 'success' : 'warning'">{{ ucfirst($installation->status->value) }}</x-ui.badge></td>
                                    <td data-label="Repositories" class="tabular">{{ $installation->repositories_count }}</td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                        <div class="flex flex-wrap gap-2 border-t border-border p-4">
                            <x-ui.button href="{{ route('github.installations') }}" size="sm" icon-end="chevron-right">Manage repositories</x-ui.button>
                            <x-ui.button href="{{ $installUrl }}" target="_blank" rel="noopener" variant="outline" size="sm" icon="plus">Install on another account</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
