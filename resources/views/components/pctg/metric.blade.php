@props([
    'title',
    'value',
    'live' => false,
])

<div class="pctg-metric">
    <p class="text-xs text-slate-500">
        {{ $title }}
    </p>

    <h3 class="mt-2 text-2xl font-bold" @if ($live) x-text="$store.checkout.totalLabel" @endif>
        {{ $value }}
    </h3>

    {{ $slot }}
</div>
