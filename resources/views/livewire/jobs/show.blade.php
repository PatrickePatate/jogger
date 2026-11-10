<div class="space-y-5" @if ($live) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-[0.8125rem] text-text-secondary"><a href="{{ route('jobs.index') }}" wire:navigate class="hover:underline">Jobs</a> / {{ $job->repository->full_name }}</p>
            <h1 class="truncate text-xl font-semibold">{{ $job->job_name }}</h1>
            <p class="text-text-secondary">{{ $job->workflow_name ?? 'workflow' }}</p>
        </div>
        <div class="flex items-center gap-2">
            <x-job-status-pill :status="$job->status->value === 'queued' && str_starts_with((string) $job->unmatched_reason, 'NO_MATCHING_POOL') ? 'unmatched' : $job->status->value" :conclusion="$job->conclusion?->value" />
            @if ($job->html_url)
                <x-ui.button variant="outline" size="sm" href="{{ $job->html_url }}" target="_blank" rel="noopener noreferrer">View on GitHub<span class="sr-only"> (opens in a new tab)</span></x-ui.button>
            @endif
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 space-y-5">
            <x-ui.stepper title="Progress" :steps="$steps" />

            <x-ui.card title="Log" :padded="false" wire:ignore>
                <x-slot:actions>
                    <x-ui.button variant="outline" size="xs" href="{{ route('jobs.log.download', $job) }}">Download</x-ui.button>
                </x-slot:actions>
                <div x-data="logTerminal({ url: @js(route('jobs.log', $job)), jobId: @js($job->id) })" class="border-t border-border">
                    <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2 text-[0.8125rem]">
                        <label class="sr-only" for="log-search">Search the log</label>
                        <input id="log-search" type="search" x-model="query" x-on:keydown.enter.prevent="find($event.shiftKey)" placeholder="Search…"
                               class="h-8 w-48 rounded-control border border-border-strong bg-surface px-2 text-sm focus:border-info focus:outline-none focus:ring-2 focus:ring-info/25">
                        <button type="button" class="rounded-md px-2 py-1 text-text-secondary hover:bg-ink/5" x-on:click="find(true)" aria-label="Previous match">↑</button>
                        <button type="button" class="rounded-md px-2 py-1 text-text-secondary hover:bg-ink/5" x-on:click="find(false)" aria-label="Next match">↓</button>
                        <button type="button" class="rounded-md px-2 py-1 text-info hover:bg-ink/5" x-show="earlier" x-cloak x-on:click="loadEarlier()">Load earlier lines</button>
                        <span class="ml-auto text-text-secondary" x-text="status" role="status" aria-live="polite"></span>
                    </div>
                    <div x-ref="terminal" class="h-[32rem] bg-[#0f172a] p-2" role="log" aria-label="Job log"></div>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card title="Details">
            <dl class="space-y-3 text-[0.8125rem] [&_dt]:text-text-secondary [&_dd]:break-words">
                <div><dt>Repository</dt><dd>{{ $job->repository->full_name }}</dd></div>
                <div><dt>Branch</dt><dd class="font-mono">{{ $job->head_branch ?? '—' }}</dd></div>
                <div><dt>Commit</dt><dd class="font-mono">{{ $job->head_sha ? substr($job->head_sha, 0, 7) : '—' }}</dd></div>
                <div><dt>Started by</dt><dd>{{ $job->actor ?? '—' }}</dd></div>
                <div><dt>Labels</dt><dd class="mt-1 flex flex-wrap gap-1.5">@foreach ($job->labels as $label)<x-ui.badge>{{ $label }}</x-ui.badge>@endforeach</dd></div>
                <div><dt>Pool</dt><dd>{{ $pool?->name ?? '—' }}</dd></div>
                <div><dt>Host</dt><dd>{{ $runner?->host?->name ?? '—' }}</dd></div>
                <div><dt>Runner</dt>
                    <dd>
                        @if ($runner)
                            <span class="font-mono">{{ $runner->name }}</span>
                            <x-runner-state-pill class="mt-1" :state="$runner->state->value" />
                            @if ($runner->exit_reason)<p class="mt-1 text-text-secondary">Ended: {{ str_replace('_', ' ', $runner->exit_reason) }}@if ($runner->exit_code !== null) (exit {{ $runner->exit_code }})@endif</p>@endif
                        @else — @endif
                    </dd>
                </div>
                @if ($log)
                    <div><dt>Log size</dt><dd>{{ \Illuminate\Support\Number::fileSize($log->size_bytes) }}@if ($log->truncated) · truncated @endif</dd></div>
                @endif
            </dl>
        </x-ui.card>
    </div>
</div>
