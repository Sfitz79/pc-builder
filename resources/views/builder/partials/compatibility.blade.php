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
                x-text="compatibility.gpuClearance ? '✓' : '✕'"
            ></span>
        </div>

    </div>

    <div
        class="mt-6 rounded-xl border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-300"
        x-show="!compatibility.cpuMotherboard || !compatibility.ramSupported || !compatibility.powerEnough || !compatibility.gpuClearance"
    >
        <p class="font-semibold">Compatibility Warning</p>
        <p class="mt-1">One or more selected components are incompatible. Review your choices.</p>
    </div>

</x-pctg.card>
