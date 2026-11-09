@props(['label' => null, 'name' => null, 'hint' => null])

<label class="flex items-start gap-2.5">
    <input type="checkbox" @if($name) name="{{ $name }}" @endif
        {{ $attributes->class(['mt-0.5 size-4 rounded border-border-strong bg-surface accent-ink']) }}>
    <span>
        <span class="font-medium">{{ $label ?? $slot }}</span>
        @if ($hint)<span class="block text-[0.8125rem] text-text-secondary">{{ $hint }}</span>@endif
    </span>
</label>
