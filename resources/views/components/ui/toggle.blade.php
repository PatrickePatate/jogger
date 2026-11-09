@props(['label' => null, 'name' => null])

<label class="inline-flex cursor-pointer items-center gap-3">
    <input type="checkbox" role="switch" @if($name) name="{{ $name }}" @endif {{ $attributes->class(['peer sr-only']) }}>
    <span class="relative h-6 w-10 rounded-full bg-border-strong transition-colors after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-surface after:shadow after:transition-transform peer-checked:bg-success peer-checked:after:translate-x-4 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-info"></span>
    @if ($label ?? $slot->isNotEmpty())<span>{{ $label ?? $slot }}</span>@endif
</label>
