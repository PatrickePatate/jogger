{{-- Server-rendered line chart. `series` is a list of ['label' => …, 'values' => [number|null, …], 'tone' => info|success|error|warning]. --}}
@props(['series', 'from' => null, 'to' => null, 'unit' => '', 'summary' => null])

@php
    $w = 600; $h = 140; $pad = 4;
    $all = collect($series)->flatMap(fn ($s) => $s['values'])->filter(fn ($v) => $v !== null);
    $max = max(1, (float) $all->max());
    $points = function (array $values) use ($w, $h, $pad, $max) {
        $n = count($values);
        $out = [];
        foreach (array_values($values) as $i => $v) {
            if ($v === null) { continue; }
            $x = $n > 1 ? $pad + $i / ($n - 1) * ($w - 2 * $pad) : $w / 2;
            $y = $h - $pad - ((float) $v / $max) * ($h - 2 * $pad);
            $out[] = round($x, 1).','.round($y, 1);
        }
        return implode(' ', $out);
    };
    $tones = ['info' => 'text-info', 'success' => 'text-success', 'error' => 'text-error', 'warning' => 'text-warning'];
    // What a screen reader gets instead of the picture: the latest and highest value of each line.
    $text = $summary ?? collect($series)->map(function ($s) use ($unit) {
        $values = collect($s['values'])->filter(fn ($v) => $v !== null);
        return $values->isEmpty() ? "{$s['label']}: no data" : "{$s['label']}: latest ".round((float) $values->last(), 1)."{$unit}, highest ".round((float) $values->max(), 1).$unit;
    })->implode('. ');
@endphp

<figure {{ $attributes }}>
    @if ($all->isEmpty())
        <p class="grid h-36 place-items-center text-[0.8125rem] text-text-secondary">No data for this period yet.</p>
    @else
        <div class="relative">
            <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-36 w-full" role="img" aria-label="{{ $text }}">
                <line x1="0" x2="{{ $w }}" y1="{{ $h - $pad }}" y2="{{ $h - $pad }}" class="text-border" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" />
                <line x1="0" x2="{{ $w }}" y1="{{ $pad }}" y2="{{ $pad }}" class="text-border" stroke="currentColor" stroke-width="1" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
                @foreach ($series as $s)
                    <polyline points="{{ $points($s['values']) }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"
                              vector-effect="non-scaling-stroke" class="{{ $tones[$s['tone'] ?? 'info'] ?? 'text-info' }}" />
                @endforeach
            </svg>
            <span class="absolute right-0 top-0 -translate-y-1/2 bg-surface pl-1 text-[0.75rem] text-text-secondary tabular">{{ round($max, 1) }}{{ $unit }}</span>
        </div>
        <figcaption class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-[0.75rem] text-text-secondary">
            <span class="flex flex-wrap gap-x-3">
                @foreach ($series as $s)
                    <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-3 rounded bg-current {{ $tones[$s['tone'] ?? 'info'] ?? 'text-info' }}"></span>{{ $s['label'] }}</span>
                @endforeach
            </span>
            @if ($from && $to)<span class="tabular">{{ $from }} → {{ $to }}</span>@endif
        </figcaption>
        <p class="sr-only">{{ $text }}</p>
    @endif
</figure>
