@props([
    'active' => false
])

@php
    $classes = $active
        ? 'rounded-xl bg-pctg-elevated px-4 py-2 text-sm font-medium text-white'
        : 'rounded-xl px-4 py-2 text-sm font-medium text-pctg-text-secondary transition hover:bg-pctg-elevated hover:text-white';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
