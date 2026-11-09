@props(['steps', 'title' => null])

@php
    use App\Support\Ui\StepData;
    use App\Support\Ui\StepState;

    $items = collect($steps)->map(fn ($s) => $s instanceof StepData ? $s : StepData::from($s))->values();
    $total = $items->count();
    $done = $items->where('state', StepState::Done)->count();
    $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
@endphp

<section {{ $attributes->class(['rounded-card border border-border bg-surface p-5 shadow-card']) }}>
    <header class="flex items-center justify-between gap-4">
        <h2 class="min-w-0 text-base font-semibold leading-snug">{{ $title }}</h2>
        <div class="flex shrink-0 items-center gap-3">
            <span class="text-[0.8125rem] font-medium text-text-secondary tabular">{{ $done }} of {{ $total }}</span>
            <div class="h-1 w-16 overflow-hidden sm:w-24 rounded-full bg-border" role="progressbar" aria-label="Progress"
                 aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}">
                <div class="h-full rounded-full bg-ink transition-[width] duration-300" style="width: {{ $percent }}%"></div>
            </div>
        </div>
    </header>

    <ol class="mt-5">
        @foreach ($items as $i => $step)
            @php($last = $loop->last)
            <li class="flex gap-3" @if($step->state === StepState::Active) aria-current="step" @endif>
                <div class="flex flex-col items-center">
                    <span class="grid size-6 shrink-0 place-items-center">
                        @switch($step->state)
                            @case(StepState::Done)
                                <span class="grid size-6 place-items-center rounded-full bg-success text-surface"><x-icon name="check" :size="14" class="[stroke-width:2.5]" /></span>
                                @break
                            @case(StepState::Failed)
                                <span class="grid size-6 place-items-center rounded-full bg-error text-surface"><x-icon name="x" :size="14" class="[stroke-width:2.5]" /></span>
                                @break
                            @case(StepState::Active)
                                <span class="relative block size-6">
                                    <span class="absolute inset-0 rounded-full border-2 border-border-strong"></span>
                                    <svg class="absolute inset-0 size-6 animate-spin text-info motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                                    </svg>
                                </span>
                                @break
                            @case(StepState::Skipped)
                                <span class="block size-6 rounded-full border-2 border-dashed border-border-strong"></span>
                                @break
                            @default
                                <span class="block size-6 rounded-full border-2 border-border-strong"></span>
                        @endswitch
                    </span>
                    @unless ($last)
                        <span @class(['my-1 w-0.5 flex-1 min-h-4 rounded-full', 'bg-success' => $step->state === StepState::Done, 'bg-border-strong' => $step->state !== StepState::Done])></span>
                    @endunless
                </div>
                <div @class(['min-w-0 pt-0.5', 'pb-5' => ! $last])>
                    <p @class(['text-[0.9375rem] font-medium leading-snug', 'text-text-secondary' => in_array($step->state, [StepState::Pending, StepState::Skipped], true), 'text-error' => $step->state === StepState::Failed])>
                        {{ $step->label }}<span class="sr-only"> — {{ $step->state->label() }}</span>
                    </p>
                    @if ($step->detail)
                        <p class="mt-0.5 text-[0.8125rem] text-text-secondary tabular">{{ $step->detail }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>
