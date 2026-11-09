@props(['href' => null, 'icon' => null, 'danger' => false, 'type' => 'button'])

@php($classes = 'flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-ink/5 '.($danger ? 'text-error' : 'text-text'))

@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->class([$classes]) }}>@if($icon)<x-icon :name="$icon" :size="16" />@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" role="menuitem" {{ $attributes->class([$classes]) }}>@if($icon)<x-icon :name="$icon" :size="16" />@endif{{ $slot }}</button>
@endif
