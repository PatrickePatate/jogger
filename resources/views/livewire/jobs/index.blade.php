@php use App\Support\Ui\Duration; @endphp
<div class="space-y-5" @if ($polls) wire:poll.5s @endif>
    <div>
        <h1 class="text-xl font-semibold">Jobs</h1>
        <p class="text-text-secondary">Every GitHub Actions job Jogger has seen, newest first.</p>
    </div>

    <div class="grid gap-3 rounded-card border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-6">
        <x-ui.select label="Repository" name="repository" wire:model.live="repository">
            <option value="">All repositories</option>
            @foreach ($repositories as $repo)
                <option value="{{ $repo->id }}">{{ $repo->full_name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input label="Workflow" name="workflow" wire:model.live.debounce.300ms="workflow" placeholder="Any workflow" />
        <x-ui.select label="Status" name="status" wire:model.live="status">
            <option value="">Any status</option>
            @foreach (\App\Livewire\Jobs\Index::STATUSES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Pool" name="pool" wire:model.live="pool">
            <option value="">Any pool</option>
            @foreach ($pools as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Host" name="host" wire:model.live="host">
            <option value="">Any host</option>
            @foreach ($hosts as $h)
                <option value="{{ $h->id }}">{{ $h->name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="When" name="range" wire:model.live="range">
            @foreach (\App\Livewire\Jobs\Index::RANGES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div wire:loading.delay.class="opacity-60" class="transition-opacity">
        @if ($jobs->isEmpty())
            @if ($filtered)
                <x-ui.empty-state icon="list" title="No jobs match these filters" description="Try a wider time range or fewer filters.">
                    <x-ui.button variant="outline" wire:click="clearFilters">Clear filters</x-ui.button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="list" title="No jobs yet" description="Jobs appear here as soon as GitHub queues one for a repository Jogger is installed on." />
            @endif
        @else
            <x-ui.table>
                <x-slot:head>
                    <th>Status</th><th>Job</th><th>Repository</th><th>Branch</th><th class="text-right">Queued for</th><th class="text-right">Duration</th><th class="text-right">Started</th>
                </x-slot:head>
                @foreach ($jobs as $job)
                    @php
                        $unmatched = $job->status->value === 'queued' && $job->unmatched_reason !== null;
                        $queuedFor = Duration::between($job->queued_at, $job->started_at ?? ($job->isOpen() ? now() : $job->completed_at));
                        $ran = $job->started_at ? Duration::between($job->started_at, $job->completed_at ?? now()) : null;
                    @endphp
                    <tr wire:key="job-{{ $job->id }}">
                        <td data-label="Status">
                            <x-job-status-pill :status="$unmatched && str_starts_with($job->unmatched_reason, 'NO_MATCHING_POOL') ? 'unmatched' : $job->status->value" :conclusion="$job->conclusion?->value" />
                        </td>
                        <td data-label="Job">
                            <a href="{{ route('jobs.show', $job) }}" wire:navigate class="font-medium hover:underline">{{ $job->job_name }}</a>
                            <p class="text-[0.8125rem] text-text-secondary">{{ $job->workflow_name ?? 'workflow' }}</p>
                            @if ($unmatched)<p class="text-[0.8125rem] text-warning" title="{{ $job->unmatched_reason }}">{{ \Illuminate\Support\Str::after($job->unmatched_reason, ': ') }}</p>@endif
                        </td>
                        <td data-label="Repository" class="text-text-secondary">{{ $job->repository->full_name }}</td>
                        <td data-label="Branch" class="max-w-40 truncate font-mono text-[0.8125rem] text-text-secondary">{{ $job->head_branch ?? '—' }}</td>
                        <td data-label="Queued for" class="text-right tabular">{{ $queuedFor ?? '—' }}</td>
                        <td data-label="Duration" class="text-right tabular">{{ $ran ?? '—' }}</td>
                        <td data-label="Started" class="text-right text-text-secondary" title="{{ $job->queued_at?->toDayDateTimeString() }}">{{ $job->queued_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="mt-4">{{ $jobs->links() }}</div>
        @endif
    </div>
</div>
