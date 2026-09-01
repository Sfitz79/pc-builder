@props([
    'variant' => 'primary',
    'href' => null,
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'px-4 py-2 text-sm',
        'md' => 'px-6 py-3',
        'lg' => 'px-8 py-4 text-lg',
    ];

    $sizeClass = $sizes[$size] ?? $sizes['md'];

    $styles = match($variant) {
        'danger' => 'pctg-button-danger ' . $sizeClass,
        'secondary' => 'pctg-button-secondary ' . $sizeClass,
        default => 'pctg-button power-pulse ' . $sizeClass
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $styles]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['class' => $styles]) }}>
        {{ $slot }}
    </button>
@endif
