@props(['label' => null, 'name' => null, 'hint' => null, 'error' => null])

<x-ui.field :label="$label" :name="$name" :hint="$hint" :error="$error" :class="$attributes->get('class')">
    <select @if($name) id="{{ $name }}" name="{{ $name }}" @endif
        @if($error) aria-invalid="true" @endif
        {{ $attributes->except('class')->class(['block h-9 w-full rounded-control border bg-surface px-3 text-sm focus:border-info focus:outline-none focus:ring-2 focus:ring-info/25', $error ? 'border-error' : 'border-border-strong']) }}>
        {{ $slot }}
    </select>
</x-ui.field>
