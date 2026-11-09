@props(['label' => null, 'name' => null, 'hint' => null, 'error' => null, 'type' => 'text'])

<x-ui.field :label="$label" :name="$name" :hint="$hint" :error="$error" :class="$attributes->get('class')">
    <input type="{{ $type }}" @if($name) id="{{ $name }}" name="{{ $name }}" @endif
        @if($error) aria-invalid="true" @endif
        {{ $attributes->except('class')->class(['block h-9 w-full rounded-control border bg-surface px-3 text-sm placeholder:text-text-secondary/70 focus:border-info focus:outline-none focus:ring-2 focus:ring-info/25', $error ? 'border-error' : 'border-border-strong']) }}>
</x-ui.field>
