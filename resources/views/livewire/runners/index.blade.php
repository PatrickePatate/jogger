@php use App\Support\Ui\Duration; @endphp
<div class="space-y-5" @if ($polls) wire:poll.5s @endif>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Runners</h1>
            <p class="text-text-secondary">Each runner is a container that runs one job and is then removed. Finished runners stay listed for a day.</p>
        </div>
        <x-ui.select name="state" wire:model.live="state" class="w-48" aria-label="Filter by state">
            <option value="">Current and recent</option>
            @foreach ($states as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </x-ui.select>
    </div>

    @if ($runners->isEmpty())
        <x-ui.empty-state icon="terminal" title="No runners" description="Runners are created when a job is queued for one of your pools, or ahead of time for pools that keep some warm." />
    @else
        <x-ui.table>
            <x-slot:head><th>State</th><th>Runner</th><th>Pool</th><th>Host</th><th>Job</th><th class="text-right">Age</th><th class="text-right"><span class="sr-only">Actions</span></th></x-slot:head>
            @foreach ($runners as $runner)
                <tr wire:key="runner-{{ $runner->id }}">
                    <td data-label="State"><x-runner-state-pill :state="$runner->state->value" /></td>
                    <td data-label="Runner">
                        <button type="button" class="font-mono text-[0.8125rem] hover:underline" wire:click="select('{{ $runner->id }}')" aria-expanded="{{ $selected === $runner->id ? 'true' : 'false' }}">{{ $runner->name }}</button>
                        @if ($runner->exit_reason && $runner->state->isTerminal())<p class="text-[0.8125rem] text-text-secondary">{{ str_replace('_', ' ', $runner->exit_reason) }}</p>@endif
                    </td>
                    <td data-label="Pool">{{ $runner->pool->name }}</td>
                    <td data-label="Host" class="text-text-secondary">{{ $runner->host?->name ?? '—' }}</td>
                    <td data-label="Job">
                        @if ($runner->job)<a href="{{ route('jobs.show', $runner->job) }}" wire:navigate class="hover:underline">{{ $runner->job->job_name }}</a>@else<span class="text-text-secondary">—</span>@endif
                    </td>
                    <td data-label="Age" class="text-right tabular text-text-secondary">{{ Duration::between($runner->created_at, $runner->finished_at ?? now()) }}</td>
                    <td class="text-right">
                        @can('update', $runner->pool)
                            @if ($runner->state->isLive())
                                @if ($confirmingForce === $runner->id)
                                    <span class="inline-flex flex-wrap items-center justify-end gap-2" role="alert">
                                        <span class="text-[0.8125rem] text-error">This kills the running job.</span>
                                        <x-ui.button size="xs" variant="danger" wire:click="terminate('{{ $runner->id }}', true)">Terminate anyway</x-ui.button>
                                        <x-ui.button size="xs" variant="ghost" wire:click="cancelForce">Keep</x-ui.button>
                                    </span>
                                @else
                                    <x-ui.button size="xs" variant="destructive" wire:click="terminate('{{ $runner->id }}')"
                                        :wire:confirm="$runner->state->value === 'busy' ? null : 'Terminate '.$runner->name.'?'">Terminate</x-ui.button>
                                @endif
                            @endif
                        @endcan
                    </td>
                </tr>
                @if ($selected === $runner->id)
                    <tr wire:key="runner-detail-{{ $runner->id }}">
                        <td colspan="7" class="bg-bg/60">
                            <x-ui.stepper title="Lifecycle of {{ $runner->name }}" :steps="$this->lifecycle($runner)" class="shadow-none" />
                            @if ($runner->job)
                                <p class="mt-3">
                                    <x-ui.button size="sm" variant="secondary" :href="route('jobs.show', $runner->job)" wire:navigate>View job and logs</x-ui.button>
                                </p>
                            @endif
                        </td>
                    </tr>
                @endif
            @endforeach
        </x-ui.table>
    @endif
</div>
