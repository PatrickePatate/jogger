@props(['label' => null, 'name' => null, 'hint' => null, 'error' => null, 'for' => null])

@php($id = $for ?? $name)

<div {{ $attributes->only('class')->class(['space-y-1.5']) }}>
    @if ($label)
        <label @if($id) for="{{ $id }}" @endif class="block text-[0.8125rem] font-medium">{{ $label }}</label>
    @endif
    {{ $slot }}
    @if ($error)
        <p class="flex items-center gap-1 text-[0.8125rem] text-error" role="alert"><x-icon name="x-circle" :size="14" />{{ $error }}</p>
    @elseif ($hint)
        <p class="text-[0.8125rem] text-text-secondary">{{ $hint }}</p>
    @endif
</div>
