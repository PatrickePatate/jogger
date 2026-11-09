@props(['label' => null, 'name' => null, 'hint' => null, 'error' => null, 'rows' => 4])

<x-ui.field :label="$label" :name="$name" :hint="$hint" :error="$error" :class="$attributes->get('class')">
    <textarea rows="{{ $rows }}" @if($name) id="{{ $name }}" name="{{ $name }}" @endif
        @if($error) aria-invalid="true" @endif
        {{ $attributes->except('class')->class(['block w-full rounded-control border bg-surface px-3 py-2 text-sm focus:border-info focus:outline-none focus:ring-2 focus:ring-info/25', $error ? 'border-error' : 'border-border-strong']) }}>{{ $slot }}</textarea>
</x-ui.field>
