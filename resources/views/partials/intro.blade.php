<div
    data-pctg-intro
    data-pctg-duration="3600"
    class="pctg-grid-bg fixed inset-0 z-[999] flex items-center justify-center overflow-hidden bg-black"
    role="status"
    aria-label="Loading PC Builder"
>

    <div class="pctg-scan-line"></div>

    {{-- Startup artwork, supplied by the Boss.

         Source was a 1408x768 PNG at 2.08 MB. This overlay renders on EVERY
         page view before the site resolves, so that size cannot go on the
         critical path. scripts/prep-startup-image.cs re-encodes it as a
         progressive JPEG at the 1200px the overlay actually paints: the top
         half is 76 KB, the full frame 137 KB.

         WHY TOP HALF: the supplied artwork is a wide frame where the PC sits
         in the lower half and the headline sits in the top half. Painting only
         the top keeps the machine visible behind the panel without pushing the
         status text off screen on short viewports.

         ILLUSTRATIVE, AND LABELLED AS SUCH. The artwork has specification
         telemetry baked into it - "Intel Core i9 5.8 GHz 100% Load", "RTX 4080
         98% Load", "32GB DDR5 6400 MHz" - and it is a generated image, not a
         photograph of a machine we built. On its own that reads as a live spec
         sheet for a real system. The caption below marks it as illustration so
         it cannot be taken for a specification or for a customer's machine.
         Do not remove that caption without replacing the telemetry in the art.
    --}}
    <img
        src="{{ asset('img/brand/startup-hero-top.jpg') }}"
        alt=""
        width="1200"
        height="328"
        fetchpriority="high"
        decoding="async"
        class="pointer-events-none absolute inset-x-0 top-0 h-[38vh] w-full object-cover opacity-45"
        aria-hidden="true"
    >
    <div class="pointer-events-none absolute inset-x-0 top-0 h-[38vh] bg-gradient-to-b from-black/40 via-black/70 to-black" aria-hidden="true"></div>

    <div class="relative w-full max-w-md px-6 text-center">

        <div class="pctg-boot-logo pctg-pulse text-7xl font-black text-red-500">
            PCTG
        </div>

        <div class="mt-4 text-sm font-semibold uppercase tracking-[.35em] text-slate-500">
            Power Pulse Engine
        </div>

        <p class="mt-3 text-[11px] uppercase tracking-[0.18em] text-slate-600">
            Illustrative image
        </p>

        <div class="mt-4 text-sm font-semibold uppercase tracking-[.35em] text-slate-500">
            Power Pulse Engine
        </div>

        <div class="mt-10 space-y-3 text-left">

            <div data-pctg-status hidden class="flex items-center gap-3 text-slate-300">
                <span class="pctg-check-pop inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-sm text-red-400">✓</span>
                <span>Compatibility Engine Online</span>
            </div>

            <div data-pctg-status hidden class="flex items-center gap-3 text-slate-300">
                <span class="pctg-check-pop inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-sm text-red-400">✓</span>
                <span>AI Recommendation System Active</span>
            </div>

            <div data-pctg-status hidden class="flex items-center gap-3 text-slate-300">
                <span class="pctg-check-pop inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-sm text-red-400">✓</span>
                <span>FPS Prediction Models Loaded</span>
            </div>

        </div>

        <div class="mt-10 h-1 w-full overflow-hidden rounded-full bg-slate-800">
            <div class="pctg-boot-bar h-full rounded-full"></div>
        </div>

    </div>

</div>
