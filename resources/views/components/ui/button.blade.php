{{--
    Flat, shadcn-inspired button.
    variant: primary | secondary | outline | ghost | destructive (soft red) | danger (solid red) | link
    size:    xs | sm | md | lg        iconOnly: square button (give it an aria-label)
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'iconEnd' => null,
    'iconOnly' => false,
    'loading' => false,
    'pill' => false,
])

@php
    $base = 'group/btn inline-flex shrink-0 select-none items-center justify-center whitespace-nowrap border bg-clip-padding font-medium '
        .'outline-none transition-all duration-150 active:translate-y-px '
        .'focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 '
        .'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 '
        .'[&_svg]:pointer-events-none [&_svg]:shrink-0 '
        .($pill ? 'rounded-full' : 'rounded-[10px]');

    $text = ['xs' => 'text-xs', 'sm' => 'text-[0.8125rem]', 'md' => 'text-sm', 'lg' => 'text-sm'][$size];
    $box = $iconOnly
        ? ['xs' => 'size-7', 'sm' => 'size-8', 'md' => 'size-9', 'lg' => 'size-10'][$size]
        : ['xs' => 'h-7 gap-1 px-2.5', 'sm' => 'h-8 gap-1.5 px-3', 'md' => 'h-9 gap-1.5 px-3.5', 'lg' => 'h-10 gap-2 px-5'][$size];

    $variants = [
        'primary' => 'border-transparent bg-ink text-surface hover:bg-ink/80',
        'secondary' => 'border-transparent bg-muted text-text hover:bg-muted/70',
        'outline' => 'border-border bg-surface text-text hover:bg-muted dark:border-border-strong',
        'ghost' => 'border-transparent bg-transparent text-text hover:bg-muted',
        'destructive' => 'border-transparent bg-danger/10 text-danger-ink hover:bg-danger/20 focus-visible:border-danger/40 focus-visible:ring-danger/25',
        'danger' => 'border-transparent bg-danger text-white hover:bg-danger-hover focus-visible:ring-danger/30',
        'link' => 'border-transparent bg-transparent text-text underline-offset-4 hover:underline',
    ];

    $classes = "$base $text $box {$variants[$variant]}";
    $iconSize = $size === 'xs' ? 14 : 16;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes]) }} @if($loading) aria-disabled="true" @endif>
        @if ($loading)<x-icon name="spinner" :size="$iconSize" class="animate-spin motion-reduce:animate-none" />@elseif ($icon)<x-icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconEnd)<x-icon :name="$iconEnd" :size="$iconSize" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class([$classes]) }} @if($loading) disabled aria-busy="true" @endif>
        @if ($loading)<x-icon name="spinner" :size="$iconSize" class="animate-spin motion-reduce:animate-none" />@elseif ($icon)<x-icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconEnd)<x-icon :name="$iconEnd" :size="$iconSize" />@endif
    </button>
@endif
