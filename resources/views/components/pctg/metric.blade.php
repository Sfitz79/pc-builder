@props([
    'title',
    'value',
    'live' => false,
    // Alpine expression that replaces $value at runtime.
    //
    // $value is server-rendered through Blade's double-brace echo, so it
    // CANNOT carry a live client-side number. Anything computed in
    // builderState - the compatibility checks, the build total - has to be
    // bound through expr instead, or it has to stay blank. A hardcoded string
    // here is a claim we never measured.
    //
    // Note: do not write Blade echo braces inside this array. They are
    // compiled, not commented, and break the view.
    'expr' => null,
])

<div class="pctg-metric">
    <p class="text-xs text-slate-500">
        {{ $title }}
    </p>

    <h3
        class="mt-2 text-2xl font-bold"
        @if ($live)
            x-text="$store.checkout.totalLabel"
        @elseif ($expr)
            x-text="{{ $expr }}"
        @endif
    >
        {{ $value }}
    </h3>

    {{ $slot }}
</div>
