<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Secrets</h1>
        <p class="text-text-secondary">Environment variables and registry credentials given to runner containers. A value at a narrower scope wins: pool, then installation, then everywhere. Secrets of your workflows stay in GitHub.</p>
    </div>

    @if ($scopes !== [])
        <x-ui.card title="Add or rotate a secret" description="Saving a name that already exists replaces its value for new runners. Values are encrypted and cannot be read back.">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2" autocomplete="off">
                <x-ui.select label="Applies to" name="scope" wire:model.live="scope" :error="$errors->first('scope')">
                    @foreach ($scopes as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </x-ui.select>
                @if ($scope !== 'global')
                    <x-ui.select :label="$scope === 'pool' ? 'Pool' : 'Installation'" name="scopeId" wire:model="scopeId">
                        @foreach ($targets[$scope] ?? [] as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                @else
                    <div class="hidden sm:block"></div>
                @endif
                <x-ui.select label="Type" name="kind" wire:model.live="kind">
                    @foreach ($kinds as $k)
                        <option value="{{ $k->value }}">{{ $k->label() }}</option>
                    @endforeach
                </x-ui.select>
                @if ($kind === 'registry')
                    <x-ui.input label="Registry host" name="name" wire:model="name" placeholder="ghcr.io" :error="$errors->first('name')" />
                    <x-ui.input label="Username" name="username" wire:model="username" autocomplete="off" :error="$errors->first('username')" />
                    <x-ui.input label="Password or token" name="value" type="password" wire:model="value" autocomplete="new-password" :error="$errors->first('value')" />
                @else
                    <x-ui.input label="Name" name="name" wire:model="name" placeholder="HTTPS_PROXY" class="font-mono" :error="$errors->first('name')" />
                    <div class="sm:col-span-2">
                        <x-ui.textarea label="Value" name="value" wire:model="value" rows="3" class="font-mono" spellcheck="false" autocomplete="off"
                                       hint="Masked as *** in job logs." :error="$errors->first('value')" />
                    </div>
                @endif
                <div class="sm:col-span-2"><x-ui.button type="submit">Save secret</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card title="Stored secrets" :padded="false">
        @if ($secrets->isEmpty())
            <div class="p-5"><x-ui.empty-state icon="check-circle" title="No secrets yet" description="Add proxy settings, tokens or registry credentials your runners need." /></div>
        @else
            <x-ui.table flush>
                <x-slot:head><th>Name</th><th>Type</th><th>Applies to</th><th>Changed</th><th class="text-right"><span class="sr-only">Actions</span></th></x-slot:head>
                @foreach ($secrets as $secret)
                    <tr wire:key="secret-{{ $secret['id'] }}">
                        <td data-label="Name" class="font-mono text-[0.8125rem]">{{ $secret['name'] }}</td>
                        <td data-label="Type">{{ $secret['kind']->label() }}</td>
                        <td data-label="Applies to">{{ $secret['scope']->label() }}@if ($secret['target']) · <span class="text-text-secondary">{{ $secret['target'] }}</span>@endif</td>
                        <td data-label="Changed" class="text-text-secondary">{{ $secret['rotated'] ? 'Rotated' : 'Created' }} {{ $secret['updated'] }}</td>
                        <td class="text-right">
                            @if ($secret['manageable'])
                                <x-ui.button size="xs" variant="outline" wire:click="rotate('{{ $secret['id'] }}')">Rotate</x-ui.button>
                                <x-ui.button size="xs" variant="destructive" wire:click="delete('{{ $secret['id'] }}')" wire:confirm="Delete {{ $secret['name'] }}? New runners will start without it.">Delete</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
