<div x-on:close-modal.window="$wire.resetWizard()">
    <x-ui.modal name="enroll-host" title="Add a host" max-width="max-w-2xl">
        <div @if ($plainToken && ! $host) wire:poll.3s @endif class="space-y-5">
            @if (! $plainToken)
                <form wire:submit="create" class="space-y-4">
                    <p class="text-text-secondary">Jogger will generate a one-time token. You then run one command on the machine; it needs Docker and an outbound connection to this server, no inbound ports.</p>
                    <x-ui.input label="Name (optional)" name="hostName" wire:model="hostName" placeholder="build-01" hint="Defaults to the machine's hostname." :error="$errors->first('hostName')" />
                    <div class="flex justify-end"><x-ui.button type="submit">Create token</x-ui.button></div>
                </form>
            @else
                <div x-data="{ copied: false }">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[0.8125rem] font-medium">Run this on the new machine</p>
                        <x-ui.button size="xs" variant="outline" icon="check"
                                     x-on:click="navigator.clipboard.writeText(@js($command)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                            <span x-text="copied ? 'Copied' : 'Copy'"></span>
                        </x-ui.button>
                    </div>
                    <pre class="mt-2 overflow-x-auto rounded-control border border-border bg-ink/5 p-3 font-mono text-[0.8125rem]" data-testid="enroll-command">{{ $command }}</pre>
                    <p class="mt-2 text-[0.8125rem] text-text-secondary">The token works once and expires in an hour. It is only shown here: keep this dialog open until the agent connects.</p>
                </div>

                @if ($expired)
                    <p class="rounded-control bg-warning/10 px-3 py-2 text-[0.8125rem] text-warning" role="status">This token has expired. Close the dialog and create a new one.</p>
                @endif

                <x-ui.stepper title="Connecting" :steps="$steps" />

                <div class="flex justify-end">
                    <x-ui.button variant="outline" wire:click="resetWizard" x-on:click="$dispatch('close-modal', 'enroll-host')">{{ $host ? 'Done' : 'Cancel' }}</x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.modal>
</div>
