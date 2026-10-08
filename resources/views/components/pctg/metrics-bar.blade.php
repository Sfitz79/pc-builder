@props([
    'buildTotal' => null,
    'live' => false,
])

{{-- Only renders a score for a COMPLETE selection. See the note beside the
     Compatibility metric: the client-side checks are optimistic and report
     true when a part is simply missing, so a partial build must not be
     scored. --}}
@php
    $compatibilityExpr = "Object.keys(selected).every(k => selected[k])"
        . " ? Object.values(compatibility).filter(Boolean).length + '/' + Object.keys(compatibility).length"
        . " : '—'";
@endphp

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
             arrives. An honest blank beats a fabricated price.

             The dash is a literal em dash character, NOT the '&mdash;' entity.
             The entity was passed as a PHP string into a value that the metric
             component renders through {{ }}, which escapes '&' to '&amp;', so
             customers saw the literal text "&mdash;" instead of a dash. An HTML
             entity only renders when it reaches the browser unescaped. --}}
        <x-pctg.metric
            title="Build Cost"
            :value="$buildTotal ?? '—'"
            :live="$live"
        />

        {{-- The three metrics below used to read Compatibility "100%",
             Performance "92/100" and FPS "165" as hardcoded strings.

             They were fabrications: identical for every customer and every
             build. Someone who selected an incompatible board, or a slow card,
             was still told 100%, 92/100 and 165 FPS. That is a specific
             performance claim about a specific machine that we never measured,
             printed directly under our own "an honest blank beats a fabricated
             price" note.

             COMPATIBILITY is now bound to the real checks builder.js computes
             from the current selection (cpuMotherboard, ramSupported,
             powerEnough, gpuClearance).

             It is gated on a COMPLETE build on purpose. Those checks are
             written optimistically - each is `!part || ...` - so on a
             half-finished build they report "true" simply because the part is
             missing. A partial build would otherwise render a confident "4/4"
             for a machine that does not exist yet, which is the same
             fabrication as the hardcoded 100%, just computed. The score only
             appears once all eight categories have a component.

             PERFORMANCE stays blank: there is no measured source for it.

             FPS stays blank: /builder/fps returns fpsResults, but its element
             shape has not been read from a live response. Guessing the shape
             would ship another wrong number, which is precisely the mistake
             being removed here. Read it from a live payload, then bind it. --}}
        <x-pctg.metric
            title="Compatibility"
            value="—"
            :expr="$compatibilityExpr"
        />

        <x-pctg.metric
            title="Performance"
            value="—"
        />

        <x-pctg.metric
            title="FPS"
            value="—"
        />
    </div>
</div>
