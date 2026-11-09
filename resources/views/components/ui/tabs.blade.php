@props(['tabs', 'default' => null])

@php($first = $default ?? array_key_first($tabs))

<div x-data="{ tab: '{{ $first }}' }" {{ $attributes }}>
    <div role="tablist" class="flex gap-1 border-b border-border">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                    x-on:click="tab = '{{ $key }}'" x-bind:aria-selected="(tab === '{{ $key }}').toString()"
                    x-bind:class="tab === '{{ $key }}' ? 'border-ink text-text' : 'border-transparent text-text-secondary hover:text-text'"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors">{{ $label }}</button>
        @endforeach
    </div>
    <div class="pt-4">{{ $slot }}</div>
</div>
