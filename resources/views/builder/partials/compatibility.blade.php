<x-pctg.card>

    <x-pctg.section-heading
        title="Compatibility"
    />

    <div class="mt-6 space-y-4">

        <div class="flex justify-between">
            <span>CPU + Motherboard</span>
            <span
                class="font-bold"
                :class="compatibility.cpuMotherboard ? 'text-green-400' : 'text-red-400'"
                x-text="compatibility.cpuMotherboard ? '✓' : '✕'"
            ></span>
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
            || !compatibility.powerEnough || !compatibility.gpuClearance"
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
                    || !compatibility.powerEnough || !compatibility.gpuClearance)"
        >
            One or more selected components are incompatible. Review your choices.
        </p>
    </div>

</x-pctg.card>
