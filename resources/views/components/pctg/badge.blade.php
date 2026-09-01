@props([
    'variant' => 'ai',
    'dot' => false,
])

@php
    $variants = [
        'ai' => 'bg-red-500/10 text-red-300 border border-red-500/20',
        'success' => 'bg-green-500/10 text-green-400 border border-green-500/20',
        'warning' => 'bg-yellow-500/10 text-yellow-300 border border-yellow-500/20',
        'gaming' => 'bg-purple-500/10 text-purple-300 border border-purple-500/20'
    ];
@endphp

<span {{ $attributes->merge(['class' => 'pctg-badge ' . $variants[$variant]]) }}>
    @if ($dot)
        <span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
