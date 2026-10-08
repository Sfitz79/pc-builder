{{--
    Intro overlay.

    LAYOUT (Boss instruction): the startup artwork takes the upper THREE QUARTERS and
    the grid-and-boot animation takes the bottom QUARTER. Implemented as a flex column
    with flex-[3] and flex-[1] on a zero basis, so the split is exactly 3:1 at every
    viewport rather than depending on the order things happen to stack.

    THE UPPER BAND ARTWORK.
    Supplied by the Boss: E:\Downloads\Gemini_Generated_Image_bsi732bsi732bsi7.png -
    the "BUILD YOUR GAMER'S EDGE" render. It was 2,029 KB, which is far too heavy for
    the critical path of every page view (this overlay is on every page), so
    scripts/genie-intro-artwork.php re-encodes it to 1408x768 JPEG at 201 KB - 90%
    smaller, same pixels, same colours, nothing recoloured or cropped.

    WHY THE "ILLUSTRATIVE IMAGE" CAPTION IS BACK.
    The artwork has telemetry baked into it: "Intel Core i9 5.8 GHz 100% Load",
    "NVIDIA RTX 4080 2520 MHz 98% Load", "32GB DDR5 6400 MHz 64% Load". On its own,
    next to a real Compatibility Engine status list, that reads as a live spec sheet
    for a machine we built - and it is not one. It is artwork. The caption was
    previously removed because the band used to hold the plain Business badge, which
    carries no such claim; it is required again now that the artwork is back.

    The bottom quarter keeps the grid background, scan line, PCTG mark and the boot
    progress bar. Those were on the outer container before, so they covered the whole
    overlay; the instruction is that the animation occupies the bottom quarter.
--}}
<div
    data-pctg-intro
    data-pctg-duration="3000"
    class="fixed inset-0 z-[999] flex flex-col overflow-hidden bg-black"
    role="status"
    aria-label="Loading PC Builder"
>

    {{-- Upper three quarters: the supplied startup artwork --}}
    <div class="relative flex flex-[3] basis-0 flex-col items-center justify-center px-6">

        <img
            src="{{ asset('img/brand/startup-hero-top.jpg') }}"
            alt=""
            width="1408"
            height="768"
            fetchpriority="high"
            decoding="async"
            class="max-h-full w-auto max-w-full object-contain"
            aria-hidden="true"
        >

        {{-- Mandatory because the artwork carries fabricated telemetry. See above. --}}
        <p class="mt-3 shrink-0 text-center text-[10px] uppercase tracking-[0.25em] text-slate-600">
            Illustrative image
        </p>

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