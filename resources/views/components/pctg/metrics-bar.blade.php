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
        <x-pctg.metric
            title="Build Cost"
            :value="$buildTotal ?? '£1,799'"
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
