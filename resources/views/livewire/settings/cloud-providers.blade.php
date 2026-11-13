<div class="mx-auto max-w-5xl space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Cloud hosts</h1>
        <p class="text-text-secondary">When jobs wait and no host has room, Jogger creates a server at a provider, runs the jobs there and deletes the server once it has been idle. Only administrators see this page.</p>
    </div>

    {{-- Providers --}}
    <x-ui.card title="Providers" description="Cloud accounts Jogger may create servers in. An inactive provider gets no new servers; its idle servers are still deleted." :padded="false">
        @if ($providers->isEmpty())
            <div class="p-5"><x-ui.empty-state icon="server" title="No providers yet" description="Add a cloud account below. Templates use it to create servers." /></div>
        @else
            <x-ui.table flush>
                <x-slot:head><th>Name</th><th>Driver</th><th>Status</th><th class="text-right">Templates</th><th class="text-right"><span class="sr-only">Actions</span></th></x-slot:head>
                @foreach ($providers as $provider)
                    <tr wire:key="provider-{{ $provider->id }}" @class(['opacity-70' => $provider->status !== 'active'])>
                        <td data-label="Name" class="font-medium">{{ $provider->name }}</td>
                        <td data-label="Driver"><x-ui.badge icon="server">{{ $drivers[$provider->driver] ?? $provider->driver }}</x-ui.badge></td>
                        <td data-label="Status">
                            @if ($provider->status === 'active')
                                <x-ui.badge tone="success" icon="check-circle">Active</x-ui.badge>
                            @else
                                <x-ui.badge tone="warning" icon="pause-circle">Inactive</x-ui.badge>
                            @endif
                        </td>
                        <td data-label="Templates" class="text-right tabular">{{ $provider->templates_count }}</td>
                        <td class="text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-ui.button size="xs" variant="outline" wire:click="toggleProvider('{{ $provider->id }}')">{{ $provider->status === 'active' ? 'Deactivate' : 'Activate' }}<span class="sr-only"> {{ $provider->name }}</span></x-ui.button>
                                <x-ui.button size="xs" variant="outline" wire:click="editProvider('{{ $provider->id }}')">Edit<span class="sr-only"> {{ $provider->name }}</span></x-ui.button>
                                <x-ui.button size="xs" variant="destructive" wire:click="deleteProvider('{{ $provider->id }}')" wire:confirm="Delete {{ $provider->name }} and its {{ $provider->templates_count }} {{ Str::plural('template', $provider->templates_count) }}? Servers still open block this.">Delete<span class="sr-only"> {{ $provider->name }}</span></x-ui.button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="$editingProvider ? 'Edit provider' : 'Add a provider'" description="Jogger checks the credentials with the provider before saving. They are stored encrypted and never shown again.">
        <form wire:submit="saveProvider" class="grid gap-4 sm:grid-cols-2" autocomplete="off">
            <x-ui.input label="Name" name="providerName" wire:model="providerName" placeholder="Hetzner main" :error="$errors->first('providerName')" />
            <x-ui.select label="Driver" name="providerDriver" wire:model.live="providerDriver" :disabled="$editingProvider" :error="$errors->first('providerDriver')">
                @foreach ($drivers as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            @foreach ($credentialFields[$providerDriver] ?? [] as $key => $label)
                <div @class(['sm:col-span-2' => count($credentialFields[$providerDriver] ?? []) === 1])>
                    <x-ui.input :type="$key === 'token' ? 'password' : 'text'" label="{{ $label }}" name="credentials.{{ $key }}" wire:model="credentials.{{ $key }}" autocomplete="new-password" class="font-mono"
                                :hint="$editingProvider ? 'Leave empty to keep the current token.' : null"
                                :error="$errors->first('credentials.'.$key)" />
                </div>
            @endforeach
            <p class="text-[0.8125rem] text-text-secondary sm:col-span-2">{{ $credentialHelp[$providerDriver] ?? '' }}</p>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <x-ui.button type="submit">{{ $editingProvider ? 'Save provider' : 'Check and add provider' }}</x-ui.button>
                @if ($editingProvider)<x-ui.button variant="ghost" wire:click="cancelProvider">Cancel</x-ui.button>@endif
            </div>
        </form>
    </x-ui.card>

    {{-- Templates --}}
    <x-ui.card title="Templates" description="Which server to create, and how many may exist for the pools that use it." :padded="false">
        @if ($templates->isEmpty())
            <div class="p-5"><x-ui.empty-state icon="cpu" title="No templates yet" description="Add a template and link it to a pool. Jogger then creates servers for that pool when its jobs wait." /></div>
        @else
            <x-ui.table flush>
                <x-slot:head>
                    <th>Name</th><th>Provider</th><th>Server</th><th class="text-right">Jobs per server</th><th class="text-right">Warm</th><th class="text-right">Maximum</th><th>Pools</th><th class="text-right">Open servers</th><th class="text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>
                @foreach ($templates as $template)
                    <tr wire:key="template-{{ $template->id }}">
                        <td data-label="Name" class="font-medium">{{ $template->name }}</td>
                        <td data-label="Provider">
                            {{ $template->provider->name }}
                            @if ($template->provider->status !== 'active')<x-ui.badge tone="warning" icon="pause-circle" class="ml-1">Inactive</x-ui.badge>@endif
                        </td>
                        <td data-label="Server" class="font-mono text-[0.8125rem]">{{ $template->server_type }} · {{ $template->location }}</td>
                        <td data-label="Jobs per server" class="text-right tabular">{{ $template->max_runners_per_host }}</td>
                        <td data-label="Warm" class="text-right tabular">{{ $template->warm_hosts }}</td>
                        <td data-label="Maximum" class="text-right tabular">{{ $template->max_hosts }}</td>
                        <td data-label="Pools" class="text-text-secondary">{{ $template->pools->isEmpty() ? 'None' : $template->pools->pluck('name')->implode(', ') }}</td>
                        <td data-label="Open servers" class="text-right tabular">{{ $template->open_hosts }}</td>
                        <td class="text-right"><div class="flex flex-wrap justify-end gap-2">
                            <x-ui.button size="xs" variant="outline" wire:click="editTemplate('{{ $template->id }}')">Edit<span class="sr-only"> {{ $template->name }}</span></x-ui.button>
                            <x-ui.button size="xs" variant="destructive" wire:click="deleteTemplate('{{ $template->id }}')" wire:confirm="Delete {{ $template->name }}? Pools stop using it for new servers.">Delete<span class="sr-only"> {{ $template->name }}</span></x-ui.button>
                        </div></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    @if ($providers->isEmpty())
        <p class="text-[0.8125rem] text-text-secondary">Add a provider before you add a template.</p>
    @else
        <x-ui.card :title="$editingTemplate ? 'Edit template' : 'Add a template'" description="A template says which server to create, and how many may exist for the pools that use it.">
            <form wire:submit="saveTemplate" class="grid gap-4 sm:grid-cols-2" autocomplete="off">
                <h3 class="border-b border-border pb-1 text-[0.8125rem] font-semibold uppercase tracking-wide text-text-secondary sm:col-span-2">Server</h3>
                <x-ui.input label="Name" name="name" wire:model="name" placeholder="cx22-fsn1" :error="$errors->first('name')" />
                <x-ui.select label="Provider" name="cloud_provider_id" wire:model="cloud_provider_id" :error="$errors->first('cloud_provider_id')">
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->name }}{{ $provider->status === 'active' ? '' : ' (inactive)' }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Server type" name="server_type" wire:model="server_type" placeholder="cx22" class="font-mono" hint="Hetzner: cx22. Scaleway: DEV1-M. DigitalOcean: s-2vcpu-4gb. AWS: c6i.xlarge. OpenStack: a flavor id." :error="$errors->first('server_type')" />
                <x-ui.input label="Location" name="location" wire:model="location" placeholder="fsn1" class="font-mono" hint="Hetzner: fsn1. Scaleway: a zone, fr-par-1. DigitalOcean: a region, fra1. AWS: a region, eu-west-3. OpenStack: a region, GRA11." :error="$errors->first('location')" />
                <x-ui.input class="sm:col-span-2" label="Image" name="image" wire:model="image" placeholder="ubuntu-24.04" class="font-mono"
                            hint="An image name, or the id of a snapshot made with the Jogger image build." :error="$errors->first('image')" />
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Provider options (JSON)" name="options" wire:model="options" rows="4" class="font-mono"
                                   hint='Optional. OpenStack: {"network_ids": ["…"], "security_groups": ["default"], "key_name": "deploy"}. AWS: {"subnet_id": "subnet-0abc", "security_group_ids": ["sg-0abc"], "spot": true}. Hetzner: ssh_keys, firewalls, networks. DigitalOcean: ssh_keys, vpc_uuid.'
                                   :error="$errors->first('options')" />
                </div>
                <h3 class="mt-2 border-b border-border pb-1 text-[0.8125rem] font-semibold uppercase tracking-wide text-text-secondary sm:col-span-2">Scaling</h3>
                <x-ui.input class="sm:col-span-2" label="Host labels" name="labels" wire:model="labels" hint="Separated by commas. A job runs on the server when all its runs-on labels are here." :error="$errors->first('labels')" />

                <x-ui.input label="Jobs per server" name="max_runners_per_host" type="number" min="1" wire:model="max_runners_per_host" hint="1 gives every job its own server." :error="$errors->first('max_runners_per_host')" />
                <x-ui.input label="Warm servers" name="warm_hosts" type="number" min="0" wire:model="warm_hosts" hint="0 creates servers only when jobs are waiting." :error="$errors->first('warm_hosts')" />
                <x-ui.input label="Maximum servers" name="max_hosts" type="number" min="1" wire:model="max_hosts" hint="Never more than this many servers for the template." :error="$errors->first('max_hosts')" />
                <x-ui.input label="Delete after idle for (seconds)" name="idle_seconds" type="number" min="60" wire:model="idle_seconds" :error="$errors->first('idle_seconds')" />
                <x-ui.input label="Maximum lifetime (seconds)" name="max_lifetime_seconds" type="number" min="600" wire:model="max_lifetime_seconds" :error="$errors->first('max_lifetime_seconds')" />
                <x-ui.input label="Hourly price" name="hourly_price" type="number" min="0" step="0.0001" wire:model="hourly_price" hint="Optional. Used only for cost estimates." :error="$errors->first('hourly_price')" />

                <h3 class="mt-2 border-b border-border pb-1 text-[0.8125rem] font-semibold uppercase tracking-wide text-text-secondary sm:col-span-2">Network and billing</h3>
                <div class="space-y-3 sm:col-span-2">
                    <x-ui.toggle label="Public IPv4" name="ipv4" wire:model="ipv4" />
                    <p class="text-[0.8125rem] text-text-secondary">GitHub's endpoints are IPv4-only. Turn this off only where the server gets IPv4 another way (NAT64 with an IPv4 gateway).</p>
                    <x-ui.toggle label="Billed by the started hour" name="hourly_billing" wire:model="hourly_billing" />
                    <p class="text-[0.8125rem] text-text-secondary">Jogger keeps a server until shortly before its paid hour ends when this is on.</p>
                </div>

                <fieldset class="space-y-2 rounded-control border border-border p-4 sm:col-span-2">
                    <legend class="text-[0.8125rem] font-medium">Pools that use this template</legend>
                    @if ($pools->isEmpty())
                        <p class="text-[0.8125rem] text-text-secondary">No pools yet. Create a pool, then link it here.</p>
                    @else
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($pools as $pool)
                                <x-ui.checkbox wire:model="pool_ids" value="{{ $pool->id }}" label="{{ $pool->name }}" />
                            @endforeach
                        </div>
                    @endif
                    @error('pool_ids')<p class="text-[0.8125rem] text-error" role="alert">{{ $message }}</p>@enderror
                </fieldset>

                <div class="flex flex-wrap gap-2 sm:col-span-2">
                    <x-ui.button type="submit">{{ $editingTemplate ? 'Save template' : 'Create template' }}</x-ui.button>
                    @if ($editingTemplate)<x-ui.button variant="ghost" wire:click="cancelTemplate">Cancel</x-ui.button>@endif
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
