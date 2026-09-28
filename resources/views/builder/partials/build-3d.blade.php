<x-pctg.card>

    <div class="flex items-center justify-between">

        <x-pctg.section-heading
            title="3D Build View"
        />

        <div class="flex gap-2">

            <x-pctg.button
                variant="secondary"
                size="sm"
                @click.prevent="renderStorefront()"
                x-bind:disabled="rendering3d"
            >
                <span x-text="rendering3d ? 'Rendering…' : 'Storefront Render'"></span>
            </x-pctg.button>

            <x-pctg.button
                variant="secondary"
                size="sm"
                @click.prevent="snapshotViewport()"
            >
                Save PNG
            </x-pctg.button>

            <x-pctg.button
                variant="secondary"
                size="sm"
                @click="toggleViewport()"
                x-text="viewportOpen ? 'Hide' : 'Show'"
            ></x-pctg.button>

        </div>

    </div>

    <p class="mt-2 text-sm text-slate-400">
        True-to-scale reference of your configured build
        <span class="text-slate-400/60">(dimensions from the live catalogue)</span>.
    </p>

    <div
        x-show="viewportOpen"
        x-cloak
        class="mt-4"
        x-init="window.pctgComfyUrl = {{ Js::from(config('aigenstudio.comfyUrl')) }}; initViewport()"
    >
        <p
            class="mb-2 text-xs text-slate-500"
            x-text="viewportHint()"
        ></p>

        <div
            id="pc-viewport"
            class="h-[380px] w-full overflow-hidden rounded-xl border border-slate-800 bg-[#0b0d12]"
        ></div>

        <p class="mt-2 text-xs text-slate-500">
            Drag to orbit · scroll to zoom · right-drag to pan
        </p>

        <div
            class="mt-3 grid grid-cols-2 gap-2 text-xs"
            x-show="viewportParts && viewportParts.length"
            x-cloak
        >
            <template x-for="part in viewportParts" :key="part.category">
                <div class="rounded-lg bg-slate-900/70 px-3 py-2">
                    <p class="text-slate-500 uppercase tracking-wide" x-text="categoryLabel(part.category)"></p>
                    <p class="font-semibold text-white" x-text="part.name"></p>
                    <p
                        class="text-slate-400"
                        x-text="part.dims ? part.dims.x + ' × ' + part.dims.y + ' × ' + part.dims.z + ' mm' : ''"
                    ></p>
                </div>
            </template>
        </div>

        <div
            class="mt-4"
            x-show="render3dUrl"
            x-cloak
        >
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-emerald-400">
                Storefront Render
            </p>

            <img
                :src="render3dUrl"
                alt="Storefront render of the build"
                class="w-full rounded-xl border border-slate-800"
            >

            <div class="mt-2 flex gap-3 text-sm">
                <a
                    :href="render3dUrl"
                    @click.prevent="downloadUrl(render3dUrl)"
                    class="text-emerald-400 hover:text-white"
                >
                    Download
                </a>
                <a
                    :href="render3dUrl"
                    target="_blank"
                    class="text-slate-400 hover:text-white"
                >
                    Open full-size
                </a>
            </div>
        </div>

        <div
            class="mt-4 rounded-xl bg-amber-500/10 p-3 text-sm text-amber-300"
            x-show="render3dError"
            x-cloak
        >
            <p x-text="render3dError"></p>
        </div>
    </div>

    <div
        class="mt-4 rounded-xl bg-slate-900/60 p-3 text-sm text-slate-400"
        x-show="!viewportOpen"
        x-cloak
    >
        Pick your parts and hit
        <span class="font-semibold text-white">Show</span>
        to preview the physical build — then
        <span class="font-semibold text-white">Storefront Render</span>
        to turn the true-to-scale geometry into a photoreal shot via Aigen Studio.
    </div>

</x-pctg.card>