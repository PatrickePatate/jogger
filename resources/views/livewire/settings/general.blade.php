<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Instance settings</h1>
        <p class="text-text-secondary">These apply to everyone. A value saved here takes precedence over the matching environment variable.</p>
    </div>
    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="General">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Instance name" name="form.instance_name" wire:model="form.instance_name" hint="Shown in page titles and e-mails." :error="$errors->first('form.instance_name')" />
                <x-ui.input label="Default runner image" name="form.default_runner_image" wire:model="form.default_runner_image" hint="Prefilled for new pools." :error="$errors->first('form.default_runner_image')" />
            </div>
        </x-ui.card>
        <x-ui.card title="Retention" description="Applied by the nightly clean-up.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Job logs (days)" type="number" min="1" name="form.log_retention_days" wire:model="form.log_retention_days" :error="$errors->first('form.log_retention_days')" />
                <x-ui.input label="Finished jobs (days)" type="number" min="1" name="form.job_retention_days" wire:model="form.job_retention_days" :error="$errors->first('form.job_retention_days')" />
                <x-ui.input label="Webhook deliveries (days)" type="number" min="1" name="form.delivery_retention_days" wire:model="form.delivery_retention_days" :error="$errors->first('form.delivery_retention_days')" />
                <x-ui.input label="Docker build cache per host (GB)" type="number" min="0" step="0.5" name="form.build_cache_max_gb" wire:model="form.build_cache_max_gb" hint="0 leaves the build cache alone." :error="$errors->first('form.build_cache_max_gb')" />
            </div>
        </x-ui.card>
        <x-ui.button type="submit">Save settings</x-ui.button>
    </form>
</div>
