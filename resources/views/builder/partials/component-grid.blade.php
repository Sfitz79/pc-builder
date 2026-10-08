<x-pctg.card>

    <x-pctg.section-heading
        title="Selected Components"
    />

        {{-- grid-cols-1 AT THE BASE, for the same reason as #build-results.

             MEASURED 2026-10-08 at 360px: this element reported clientWidth 286 against
             scrollWidth 314, so the CPU/Motherboard/GPU/RAM/Storage/PSU/Case/Cooler rows
             scrolled sideways inside their own card. With only `md:grid-cols-2`
             declared, the sub-md layout fell into a single implicit column sized
             `auto`, which is content-driven, and the row refused to fit.

             grid-cols-1 makes the mobile column minmax(0,1fr) so it fits the screen. --}}
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">

        <x-pctg.selected-component category="cpu" label="CPU" />
        <x-pctg.selected-component category="motherboard" label="Motherboard" />
        <x-pctg.selected-component category="gpu" label="GPU" />
        <x-pctg.selected-component category="ram" label="RAM" />
        <x-pctg.selected-component category="storage" label="Storage" />
        <x-pctg.selected-component category="psu" label="PSU" />
        <x-pctg.selected-component category="case" label="Case" />
        <x-pctg.selected-component category="cooler" label="CPU Cooler" />

    </div>

</x-pctg.card>
