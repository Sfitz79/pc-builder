@props([
    'padded' => true,
])

<div {{ $attributes->merge(['class' => $padded ? 'pctg-card' : 'pctg-card p-0']) }}>
    {{ $slot }}
</div>
