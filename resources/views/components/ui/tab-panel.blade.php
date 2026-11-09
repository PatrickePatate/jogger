@props(['name'])

<div role="tabpanel" id="panel-{{ $name }}" aria-labelledby="tab-{{ $name }}" x-show="tab === '{{ $name }}'" x-cloak {{ $attributes }}>{{ $slot }}</div>
