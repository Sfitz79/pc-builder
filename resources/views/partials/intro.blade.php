{{--
    Intro overlay.

    LAYOUT (Boss instruction): the PCTG Business badge takes the upper THREE
    QUARTERS, the grid-and-boot animation takes the bottom QUARTER. Implemented
    as a flex column with flex-[3] and flex-[1] on a zero basis, so the split is
    exactly 3:1 at every viewport rather than depending on the order things
    happen to stack.

    WHAT CHANGED AND WHY
    - The upper band is the Business badge. The previous upper band was
      startup-hero-top.jpg, the wide startup artwork with baked-in telemetry.
    - The grid background and scan line MOVED down into the lower band. They were
      on the outer container, so they covered the whole overlay; the instruction
      is that the grid/animation occupies the bottom quarter.
    - The "Illustrative image" caption went with the artwork it described. That
      caption existed because the startup art carries fake specification
      telemetry - "Intel Core i9 5.8 GHz 100% Load", "RTX 4080 98% Load" - which
      on its own reads as a live spec sheet for a machine we built. The badge is a
      real brand asset with no such claim, so carrying the caption over would
      mislabel it.

    ASSETS
    - img/brand/pctg-business-intro.png  700x219, 349 KB. Renders up to ~900px
      wide, so it needs more than the 68 KB nav file and less than the 934 KB
      master. Still on the critical path of every page view, which is why it was
      sized deliberately rather than shipped at source resolution.
    - The badge is 3.2:1, so it is laid out by width and centred; a fixed height
      would overflow on narrow viewports.
--}}
<div
    data-pctg-intro
    data-pctg-duration="3600"
    class="fixed inset-0 z-[999] flex flex-col overflow-hidden bg-black"
    role="status"
    aria-label="Loading PC Builder"
>

    {{-- Upper three quarters: the badge --}}
    <div class="relative flex flex-[3] basis-0 items-center justify-center px-6">
        <img
            src="{{ asset('img/brand/pctg-business-intro.png') }}"
            alt=""
            width="700"
            height="219"
            fetchpriority="high"
            decoding="async"
            class="pctg-boot-logo w-full max-w-4xl"
            aria-hidden="true"
        >
    </div>

    {{-- Bottom quarter: the grid and boot animation --}}
    <div class="pctg-grid-bg relative flex flex-[1] basis-0 flex-col justify-center border-t border-white/5 px-6 py-4">

        <div class="pctg-scan-line"></div>

        <div class="relative mx-auto w-full max-w-md">

            <div class="pctg-boot-logo pctg-pulse text-center text-3xl font-black text-red-500">
                PCTG
            </div>

            <p class="mt-1 text-center text-[10px] font-semibold uppercase tracking-[.35em] text-slate-500">
                Power Pulse Engine
            </p>

            <div class="mt-3 space-y-1.5 text-left text-sm">

                <div data-pctg-status hidden class="flex items-center gap-3 text-slate-300">
                    <span class="pctg-check-pop inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-xs text-red-400">✓</span>
                    <span>Compatibility Engine Online</span>
                </div>

                <div data-pctg-status hidden class="flex items-center gap-3 text-slate-300">
                    <span class="pctg-check-pop inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-xs text-red-400">✓</span>
                    <span>AI Recommendation System Active</span>
                </div>

                <div data-pctg-status hidden class="flex items-center gap-3 text-slate-300">
                    <span class="pctg-check-pop inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-xs text-red-400">✓</span>
                    <span>FPS Prediction Models Loaded</span>
                </div>

            </div>

            <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-slate-800">
                <div class="pctg-boot-bar h-full rounded-full"></div>
            </div>

        </div>
    </div>

</div>