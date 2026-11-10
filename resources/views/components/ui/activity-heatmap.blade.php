{{--
    GitHub-style activity calendar: one square per day, weeks as columns (Monday first), darker = more.
    $days: ['Y-m-d' => count]. $failed (optional): ['Y-m-d' => failed count] for the tooltip.
    $unit: noun for the tooltip ("job", "run"...).
--}}
@props(['days' => [], 'failed' => [], 'weeks' => 26, 'unit' => 'job'])

@php
    use Carbon\CarbonImmutable;

    $today = CarbonImmutable::today();
    $start = $today->startOfWeek()->subWeeks($weeks - 1);   // Monday of the first column
    $max = max(1, (int) max([0, ...array_values($days)]));
    $level = fn (int $n) => $n === 0 ? 0 : min(4, 1 + (int) floor(($n - 1) / max(1, $max / 4)));
    $shades = [0 => 'bg-border', 1 => 'bg-info/25', 2 => 'bg-info/50', 3 => 'bg-info/75', 4 => 'bg-info'];

    // Month label above the first column that starts a new month.
    $labels = [];
    $lastMonth = null;
    for ($w = 0; $w < $weeks; $w++) {
        $monday = $start->addWeeks($w);
        $month = $monday->format('M');
        if ($month !== $lastMonth) {
            $labels[$w] = $month;
            $lastMonth = $month;
        }
    }
    $total = array_sum($days);
    $dayNames = [0 => 'Mon', 2 => 'Wed', 4 => 'Fri'];
@endphp

<div {{ $attributes->class(['inline-block max-w-full overflow-x-auto']) }}>
    <div class="flex gap-2" role="img" aria-label="{{ $total }} {{ \Illuminate\Support\Str::plural($unit, $total) }} over the last {{ $weeks }} weeks">
        <div class="mt-[1.25rem] grid grid-rows-7 gap-[3px] pr-1 text-[0.6875rem] leading-[0.75rem] text-text-secondary" aria-hidden="true">
            @for ($d = 0; $d < 7; $d++)<span class="h-3">{{ $dayNames[$d] ?? '' }}</span>@endfor
        </div>

        <div>
            <div class="mb-1 grid h-4 gap-[3px] text-[0.6875rem] text-text-secondary" style="grid-template-columns: repeat({{ $weeks }}, 0.75rem)" aria-hidden="true">
                @for ($w = 0; $w < $weeks; $w++)
                    <span class="overflow-visible whitespace-nowrap">{{ $labels[$w] ?? '' }}</span>
                @endfor
            </div>
            <div class="grid grid-flow-col grid-rows-7 gap-[3px]" style="grid-template-columns: repeat({{ $weeks }}, 0.75rem)">
                @for ($w = 0; $w < $weeks; $w++)
                    @for ($d = 0; $d < 7; $d++)
                        @php
                            $date = $start->addWeeks($w)->addDays($d);
                            $key = $date->toDateString();
                            $n = (int) ($days[$key] ?? 0);
                            $f = (int) ($failed[$key] ?? 0);
                        @endphp
                        @if ($date->greaterThan($today))
                            <span class="size-3"></span>
                        @else
                            <span class="size-3 rounded-[3px] {{ $shades[$level($n)] }}" data-date="{{ $key }}" data-count="{{ $n }}"
                                  title="{{ $n === 0 ? 'No' : $n }} {{ \Illuminate\Support\Str::plural($unit, $n) }}{{ $f > 0 ? " ({$f} failed)" : '' }} on {{ $date->format('M j, Y') }}"></span>
                        @endif
                    @endfor
                @endfor
            </div>
        </div>
    </div>

    <div class="mt-3 flex items-center justify-end gap-1.5 text-[0.75rem] text-text-secondary" aria-hidden="true">
        Less
        @foreach ($shades as $shade)<span class="size-3 rounded-[3px] {{ $shade }}"></span>@endforeach
        More
    </div>
</div>
