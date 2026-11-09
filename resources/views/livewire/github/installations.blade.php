@php
    $statusTone = ['active' => 'success', 'suspended' => 'warning', 'deleted' => 'neutral'];
@endphp
<div class="space-y-5" wire:poll.10s>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Installations</h1>
            <p class="text-text-secondary">The GitHub accounts where the app is installed, and the repositories Jogger can run jobs for.</p>
        </div>
        @if ($installUrl)<x-ui.button href="{{ $installUrl }}" target="_blank" rel="noopener" variant="outline" size="sm" icon="plus">Install on another account</x-ui.button>@endif
    </div>

    @if ($installations->isEmpty())
        <x-ui.empty-state icon="server" title="No installations yet" description="Install the GitHub App on an account and it will appear here within seconds.">
            <x-ui.button href="{{ route('github.connect') }}" variant="outline">Open GitHub settings</x-ui.button>
        </x-ui.empty-state>
    @else
        <div class="grid gap-5 lg:grid-cols-[18rem_1fr] lg:items-start">
            <nav aria-label="Installations" class="space-y-1">
                @foreach ($installations as $installation)
                    <button type="button" wire:key="inst-{{ $installation->id }}" wire:click="select('{{ $installation->id }}')"
                            @class(['flex w-full items-center justify-between gap-2 rounded-control px-3 py-2 text-left transition-colors', 'bg-ink/5' => $selected?->is($installation), 'hover:bg-ink/5' => ! $selected?->is($installation)])
                            @if ($selected?->is($installation)) aria-current="true" @endif>
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ $installation->account_login }}</span>
                            <span class="block text-[0.8125rem] text-text-secondary">{{ $installation->account_type === \App\Domain\Github\Enums\AccountType::Organization ? 'Organization' : 'User' }} · {{ $installation->repositories_count }} {{ \Illuminate\Support\Str::plural('repository', $installation->repositories_count) }}</span>
                        </span>
                        <x-ui.badge :tone="$statusTone[$installation->status->value]">{{ ucfirst($installation->status->value) }}</x-ui.badge>
                    </button>
                @endforeach
            </nav>

            @if ($selected)
                <x-ui.card :title="$selected->account_login" wire:key="detail-{{ $selected->id }}"
                           :description="'Access: '.($selected->repository_selection->value === 'all' ? 'all repositories' : 'selected repositories').' · '.($selected->synced_at ? 'last synced '.$selected->synced_at->diffForHumans() : 'not synced from GitHub yet')">
                    <x-slot:actions>
                        <x-ui.button size="sm" variant="outline" icon="clock" wire:click="resync('{{ $selected->id }}')" wire:loading.attr="disabled" wire:target="resync"
                                     :disabled="! $selected->isActive()" title="{{ $selected->isActive() ? 'Fetch the repository list from GitHub' : 'Only active installations can be synced' }}">Resync</x-ui.button>
                    </x-slot:actions>

                    @if ($selected->status->value !== 'active')
                        <p class="mb-4 flex items-start gap-2 rounded-control bg-warning/10 px-3 py-2 text-[0.8125rem] text-warning" role="status">
                            <x-icon name="warning" :size="16" class="mt-0.5" />
                            {{ $selected->status->value === 'suspended' ? 'This installation is suspended on GitHub. Jogger will not start runners for it until it is unsuspended.' : 'This installation was removed on GitHub.' }}
                        </p>
                    @endif

                    @if ($publicCount > 0)
                        <p class="mb-4 flex items-start gap-2 rounded-control bg-warning/10 px-3 py-2 text-[0.8125rem] text-warning" role="note">
                            <x-icon name="warning" :size="16" class="mt-0.5" />
                            {{ $publicCount }} public {{ \Illuminate\Support\Str::plural('repository', $publicCount) }}. Self-hosted runners should not run code from untrusted pull requests.
                        </p>
                    @endif

                    <div class="mb-3 w-64"><x-ui.input name="search" wire:model.live.debounce.300ms="search" placeholder="Filter repositories" aria-label="Filter repositories" /></div>

                    @if ($repositories->isEmpty())
                        <x-ui.empty-state icon="search" title="{{ $search ? 'No repositories match' : 'No repositories' }}"
                                          description="{{ $search ? 'Try a different filter.' : ($selected->isActive() ? 'Choose repositories for the app on GitHub, or press Resync.' : '') }}" />
                    @else
                        <x-ui.table flush>
                            <x-slot:head><th>Repository</th><th>Visibility</th></x-slot:head>
                            @foreach ($repositories as $repository)
                                <tr wire:key="repo-{{ $repository->id }}">
                                    <td data-label="Repository" class="font-mono text-[0.8125rem]"><a href="{{ rtrim(config('jogger.github.web_url'), '/') }}/{{ $repository->full_name }}" target="_blank" rel="noopener" class="underline-offset-4 hover:underline">{{ $repository->full_name }}</a></td>
                                    <td data-label="Visibility">
                                        @if ($repository->private)<x-ui.badge icon="check-circle">Private</x-ui.badge>@else<x-ui.badge tone="warning" icon="warning">Public</x-ui.badge>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                        <div class="mt-3">{{ $repositories->links() }}</div>
                    @endif
                </x-ui.card>
            @endif
        </div>
    @endif
</div>
