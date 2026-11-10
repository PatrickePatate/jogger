<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <h1 class="text-xl font-semibold">{{ $poolId ? 'Edit pool' : 'New pool' }}</h1>
        <p class="text-text-secondary">Runners in a pool are created on demand, run one job, and are removed.</p>
    </div>

    <form wire:submit="save" class="space-y-5">
        <x-ui.card title="Where it runs jobs" description="{{ $poolId ? 'The GitHub target of a pool cannot be changed.' : 'Pick the GitHub account, then one repository or the whole organization.' }}">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Name" name="name" wire:model.blur="name" placeholder="linux-x64" :error="$errors->first('name')" />
                @if ($poolId)
                    <x-ui.input label="Target" name="scope_target" :value="$scope_target.' ('.$scope.')'" disabled />
                @else
                    <x-ui.select label="GitHub account" name="installation_id" wire:model.live="installation_id" :error="$errors->first('installation_id')">
                        @foreach ($installations as $installation)
                            <option value="{{ $installation->id }}">{{ $installation->account_login }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Scope" name="scope" wire:model.live="scope" :error="$errors->first('scope')">
                        <option value="repository">One repository</option>
                        <option value="organization">Whole organization</option>
                    </x-ui.select>
                    @if ($scope === 'repository')
                        <x-ui.select label="Repository" name="scope_target" wire:model.live="scope_target" :error="$errors->first('scope_target')">
                            <option value="">Choose a repository…</option>
                            @foreach ($repositories as $fullName)
                                <option value="{{ $fullName }}">{{ $fullName }}</option>
                            @endforeach
                        </x-ui.select>
                    @else
                        <x-ui.input label="Organization" name="scope_target" wire:model="scope_target" disabled :error="$errors->first('scope_target')" />
                    @endif
                @endif
            </div>

            @if ($publicWarning)
                <div class="mt-4 rounded-control border border-warning/50 bg-warning/10 p-4" role="alert" data-testid="public-warning">
                    <p class="flex items-center gap-2 font-semibold"><x-icon name="info" :size="16" />Public repository</p>
                    <p class="mt-1 text-[0.8125rem]">{{ $publicWarning }}</p>
                    <div class="mt-3"><x-ui.checkbox wire:model.live="confirm_public" label="I understand, attach this pool to a public repository" /></div>
                    @error('confirm_public')<p class="mt-2 text-[0.8125rem] text-error" role="alert">Confirm the warning above to continue.</p>@enderror
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Runner" description="What each runner looks like.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input class="sm:col-span-2" label="Labels" name="labels" wire:model.blur="labels" hint="Separated by commas. A job runs here when all of its runs-on labels are in this list." :error="$errors->first('labels')" />
                <x-ui.input class="sm:col-span-2" label="Image" name="image" wire:model.blur="image" :error="$errors->first('image')" />
                <x-ui.input label="CPU limit (cores)" name="cpu_limit" type="number" step="0.1" min="0.1" wire:model.blur="cpu_limit" placeholder="No limit" :error="$errors->first('cpu_limit')" />
                <x-ui.input label="Memory limit (MB)" name="memory_limit_mb" type="number" min="128" wire:model.blur="memory_limit_mb" placeholder="No limit" :error="$errors->first('memory_limit_mb')" />
                <x-ui.select label="Docker inside jobs" name="docker_mode" wire:model="docker_mode" :error="$errors->first('docker_mode')">
                    @foreach ($dockerModes as $mode)
                        <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Only on hosts labelled" name="allowed_host_labels" wire:model.blur="allowed_host_labels" placeholder="Any host" hint="Optional. Separated by commas." :error="$errors->first('allowed_host_labels')" />
            </div>
        </x-ui.card>

        @if ($poolId)
            <x-ui.card title="Image on hosts" description="Hosts download the image ahead of time so the first job starts quickly." wire:poll.5s>
                <x-slot:actions><x-ui.button size="xs" variant="outline" wire:click="pullImage">Pull again</x-ui.button></x-slot:actions>
                @if ($images->isEmpty())
                    <p class="text-[0.8125rem] text-text-secondary">No host has been asked to pull this image yet. A host pulls it when its first runner starts.</p>
                @else
                    <ul class="divide-y divide-border text-[0.8125rem]">
                        @foreach ($images as $image)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="image-{{ $image->id }}">
                                <span class="font-medium">{{ $image->host->name }}</span>
                                @if ($image->status === 'ready')
                                    <x-ui.badge tone="success" icon="check-circle">Ready</x-ui.badge>
                                @elseif ($image->status === 'failed')
                                    <x-ui.badge tone="error" icon="x-circle">Failed</x-ui.badge>
                                    <p class="w-full break-words font-mono text-[0.75rem] text-error">{{ $image->error }}</p>
                                @else
                                    <x-ui.badge tone="neutral" icon="clock">Pulling…</x-ui.badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card title="Capacity">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input label="Maximum runners" name="max_runners" type="number" min="1" wire:model.blur="max_runners" :error="$errors->first('max_runners')" />
                <x-ui.input label="Kept warm" name="min_idle" type="number" min="0" wire:model.blur="min_idle" hint="Idle runners ready before a job arrives." :error="$errors->first('min_idle')" />
                <x-ui.input label="Idle timeout (seconds)" name="idle_ttl_seconds" type="number" min="60" wire:model.blur="idle_ttl_seconds" :error="$errors->first('idle_ttl_seconds')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" href="{{ route('pools.index') }}" wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $poolId ? 'Save changes' : 'Create pool' }}</x-ui.button>
        </div>
    </form>
</div>
