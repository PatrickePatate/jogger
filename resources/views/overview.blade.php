@php
    $activity = app(\App\Domain\Jobs\Actions\GetJobActivity::class)(auth()->user());
@endphp
<x-layouts.app title="Overview">
    <div class="space-y-5">
        <x-ui.card title="CI activity" description="Jobs queued per day over the last six months">
            <x-slot:actions><span class="text-2xl font-semibold tabular" data-testid="activity-total">{{ number_format($activity['total']) }}</span></x-slot:actions>
            <x-ui.activity-heatmap :days="$activity['days']" :failed="$activity['failed']" />
        </x-ui.card>

        <x-ui.empty-state icon="home" title="More dashboard coming soon" description="Live queue, runner and host stats arrive in a later task." />
    </div>
</x-layouts.app>
