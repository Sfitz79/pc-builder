<x-pctg.card>

    <x-pctg.section-heading
        title="Compatibility"
    />

    <div class="mt-6 space-y-4">

        <p
            class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200"
            x-show="compatibilityStatus === 'error'"
        >
            Server compatibility checks are unavailable. The displayed checks may be incomplete; retry or review the selected parts before continuing.
        </p>

        <p
            class="text-xs text-slate-400"
            x-show="compatibilityStatus === 'checking'"
        >
            Verifying selected parts against the catalogue…
        </p>

        <p
            class="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200"
            x-show="compatibility.selectionValid === false"
        >
            A selected part is no longer available for this category. Choose it again from the current catalogue.
        </p>

        <div class="flex justify-between">
            <span>CPU + Motherboard</span>
            <span
                class="font-bold"
                :class="compatibility.cpuMotherboard ? 'text-green-400' : 'text-red-400'"
                x-text="compatibility.cpuMotherboard ? '✓' : '✕'"
            ></span>
        </div>

                <div class="flex justify-between">
            <span>Cooler Mount</span>
            <span class="font-bold" :class="compatibility.coolerMount ? 'text-green-400' : 'text-red-400'"
                  x-text="compatibility.coolerMount ? '✓' : '✕'"></span>
        </div>

        <div class="flex justify-between">
            <span>Storage Interface</span>
            <span class="font-bold" :class="compatibility.storageSupported ? 'text-green-400' : 'text-red-400'"
                  x-text="compatibility.storageSupported ? '✓' : '✕'"></span>
        </div>

        <div class="flex justify-between">
            <span>GPU PCIe Slot</span>
            <span class="font-bold" :class="compatibility.pcieSlot ? 'text-green-400' : 'text-red-400'"
                  x-text="compatibility.pcieSlot ? '✓' : '✕'"></span>
        </div>

        <div class="flex justify-between">
            <span>Memory Speed</span>
            <span class="font-bold" :class="compatibility.ramSpeedOptimal ? 'text-green-400' : 'text-red-400'"
                  x-text="compatibility.ramSpeedOptimal ? '✓' : '✕'"></span>
        </div>

        <div class="flex justify-between">
            <span>GPU Power Connector</span>
            <span class="font-bold" :class="compatibility.gpuPowerConnector ? 'text-green-400' : 'text-red-400'"
                  x-text="compatibility.gpuPowerConnector ? '✓' : '✕'"></span>
        </div>

        <div class="flex justify-between">
            <span>Memory Support</span>
            <span
                class="font-bold"
                :class="compatibility.ramSupported ? 'text-green-400' : 'text-red-400'"
                x-text="compatibility.ramSupported ? '✓' : '✕'"
            ></span>
        </div>

        <div class="flex justify-between">
            <span>Power Requirements</span>
            <span
                class="font-bold"
                :class="compatibility.powerEnough ? 'text-green-400' : 'text-red-400'"
                x-text="compatibility.powerEnough ? '✓' : '✕'"
            ></span>
        </div>

<div class="flex justify-between">
            <span>Case Clearance</span>
            <span
                class="font-bold"
                :class="compatibility.gpuClearance ? 'text-green-400' : 'text-red-400'"
                x-text="compatibility.gpuClearance ? '✓' : '✗'"
            ></span>
        </div>

        {{-- Real check, real data. compatibility.formFactorFits is computed from
             board.specs.form_factor against case.specs.supported_form_factors,
             both verified on production (397/397 boards, 399/399 cases), and
             enforced server-side as well as here.

             It shows PENDING rather than a tick until a board AND a case are
             both chosen, because formFactorFits defaults to false: an
             unverified fit must never render as compatible. --}}
        <div class="flex justify-between">
            <span>Board Fits Case</span>
            <span
                class="font-bold"
                :class="compatibility.formFactorFits ? 'text-green-400' : 'text-red-400'"
                x-text="!selected.motherboard || !selected.case
                    ? '—'
                    : (compatibility.formFactorFits ? '✓' : '✗')"
            ></span>
        </div>

    </div>

    <div
        class="mt-6 rounded-xl border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-300"
        x-show="(selected.motherboard && selected.case && !compatibility.formFactorFits)
            || !compatibility.cpuMotherboard || !compatibility.ramSupported
            || !compatibility.powerEnough || !compatibility.gpuClearance
            || !compatibility.coolerMount || !compatibility.storageSupported
            || !compatibility.pcieSlot || !compatibility.ramSpeedOptimal
            || !compatibility.gpuPowerConnector
            || compatibility.selectionValid === false"
    >
        <p class="font-semibold">Compatibility Warning</p>
        <p
            class="mt-1"
            x-show="selected.motherboard && selected.case && !compatibility.formFactorFits"
        >
            That motherboard does not physically fit that case. A
            <span class="font-semibold" x-text="selected.motherboard?.specs?.form_factor"></span>
            board needs a case that accepts
            <span class="font-semibold" x-text="selected.motherboard?.specs?.form_factor"></span>.
            Pick a wider case or a smaller board.
        </p>
        <p
            class="mt-1"
            x-show="!(selected.motherboard && selected.case && !compatibility.formFactorFits)
                && (!compatibility.cpuMotherboard || !compatibility.ramSupported
                    || !compatibility.powerEnough || !compatibility.gpuClearance
                    || !compatibility.coolerMount || !compatibility.storageSupported
                    || !compatibility.pcieSlot || !compatibility.ramSpeedOptimal
                    || !compatibility.gpuPowerConnector
                    || compatibility.selectionValid === false)"
        >
            One or more selected components are incompatible. Review your choices.
        </p>
    </div>

</x-pctg.card>
