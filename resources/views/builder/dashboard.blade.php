@php
    /*
     * Segment defaults, declared ONCE here so both product lines agree on where
     * the boundary is.
     *
     * BuilderController::businessBuild() passes segment/purpose/workloads.
     * The gaming entry points pass nothing at all, so these must be real
     * defaults rather than an inference: reading `$segment === null` as
     * "business" would put a gaming customer through the professional wizard.
     */
    $isBusiness = ($segment ?? 'gaming') === 'business';
    $purpose = $purpose ?? null;
    $workloads = $workloads ?? \App\Services\GeminiService::businessWorkloads();

    // Business only. The workloads the catalogue cannot actually assemble, which
    // are offered as a spec conversation instead of a build button. Empty on
    // the gaming path.
    $specOnlyWorkloads = $specOnlyWorkloads ?? [];
@endphp

<x-pctg.layouts.builder
    :segment="$isBusiness ? 'business' : 'gaming'"
    :initial-purpose="$purpose"
    :initial-workloads="$workloads"
>

    {{-- x-data was REMOVED from this wrapper.

     builderState() now lives on components/pctg/layouts/builder.blade.php, at
     the layout root. Having it here as well initialised the 83-key state a
     second time, nested inside the first, and the inner instance shadowed the
     outer one for everything below - which is why the component selector and
     the build summary could not see it.

     This div is kept purely for its vertical rhythm. --}}
    <div
        class="space-y-6"
    >

        @include('builder.partials.ai-wizard')

        {{-- grid-cols-1 AT THE BASE, NOT JUST lg:grid-cols-12.

             MEASURED 2026-10-08 at a 360px viewport: documentElement.scrollWidth 372
             against clientWidth 360, so the page still scrolled sideways on the
             narrowest phones after main was given min-w-0.

             Cause: below lg this grid had NO explicit columns - `grid gap-6
             lg:grid-cols-12` only declares tracks at the lg breakpoint. With
             grid-template-columns: none the items fall into a single IMPLICIT column
             sized `auto`, which is content-driven, so the column settled at 356px and
             pushed past the 328px content box. Every card then sat at 356px and the
             document scrolled.

             `grid-cols-1` declares the mobile column explicitly as minmax(0, 1fr), so
             it cannot exceed its container and the cards reflow to the screen instead.
             This is the mobile-first half of the layout; lg:grid-cols-12 is the
             desktop half, and neither overrides the other. --}}
        <div id="build-results" class="grid grid-cols-1 gap-6 lg:grid-cols-12">

            <div class="lg:col-span-8 space-y-6">

                @include('builder.partials.recommendations')

                @include('builder.partials.component-grid')

            </div>

            <div class="lg:col-span-4 space-y-6">

                @include('builder.partials.build-3d')

                @include('builder.partials.build-summary')

                @include('builder.partials.compatibility')

                @include('builder.partials.upgrade-suggestions')

                @include('builder.partials.build-health')

                {{-- FPS ESTIMATES ARE A GAMING CLAIM AND ARE NOT SHOWN IN BUSINESS MODE.

                     The panel is a frames-per-second estimate derived from a
                     gaming GPU tier ladder and a display resolution. Neither
                     exists for the workloads PCTG Business sells: a NAS is
                     bounded by drive bays, a server by RAM channels, and a
                     render node by VRAM. Printing "148 FPS" on any of them
                     would be a fabricated performance figure attached to a
                     quote, which is exactly the failure class this project
                     exists to prevent - and the number would be read as a
                     promise. It is omitted rather than relabelled. --}}
                @unless ($isBusiness)
                    @include('builder.partials.fps-panel')
                @endunless

            </div>

        </div>

        <x-pctg.component-selector />

    </div>

</x-pctg.layouts.builder>
