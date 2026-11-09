@props(['stack' => true, 'flush' => false])

<div @class(['overflow-x-auto bg-surface', 'rounded-card border border-border' => ! $flush])>
    <table {{ $attributes->class(['w-full text-left text-sm', 'table-stack' => $stack]) }}>
        @isset($head)
            <thead class="border-b border-border bg-bg/60 text-[0.8125rem] text-text-secondary">
                <tr class="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">{{ $head }}</tr>
            </thead>
        @endisset
        <tbody class="divide-y divide-border [&>tr>td]:px-4 [&>tr>td]:py-2.5">{{ $slot }}</tbody>
    </table>
</div>
