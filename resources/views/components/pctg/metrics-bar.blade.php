@props([
    'buildTotal' => null,
    'live' => false,
])

<div
    class="
        sticky
        top-16
        z-40
        border-b
        border-slate-800
        bg-[#0b0d12]/90
        backdrop-blur-xl
    "
>
    <div
        class="
            grid
            grid-cols-2
            gap-4
            p-4
            md:grid-cols-4
        "
    >
        {{-- Placeholder was the literal '£1,799'. That is a number we invented, and it
             is shown as a price before any real quote exists. Worse, it sat
             ABOVE our own measured 1080p entry price (£1,300) and close to the
             1440p floor (£1,440), so a customer with an unquoted build was shown
             a figure that implies we had already costed their machine.

             Now it shows a dash until the live price or a stored build total
             arrives. An honest blank beats a fabricated price. --}}
        <x-pctg.metric
            title="Build Cost"
            :value="$buildTotal ?? '&mdash;'"
            :live="$live"
        />

        <x-pctg.metric
            title="Compatibility"
            value="100%"
        />

        <x-pctg.metric
            title="Performance"
            value="92/100"
        />

        <x-pctg.metric
            title="FPS"
            value="165"
        />
    </div>
</div>
