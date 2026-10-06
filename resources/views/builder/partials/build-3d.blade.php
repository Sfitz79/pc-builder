<x-pctg.card>

    <div class="flex items-center justify-between">

        <x-pctg.section-heading
            title="3D Build View"
        />

        <div class="flex gap-2">

            {{-- Only offered when the server says the render studio can actually
                 be reached. Previously this button was always shown and could
                 only fail for a real customer, after a 150 second wait. --}}
            <x-pctg.button
                variant="secondary"
                size="sm"
                @click.prevent="renderStorefront()"
                x-show="storefrontRenderAvailable"
                x-cloak
                x-bind:disabled="rendering3d"
            >
                <span x-text="rendering3d ? 'Rendering…' : 'Storefront Render'"></span>
            </x-pctg.button>

            {{--
                INTERACTIVE 3D VIEWPORT - WITHDRAWN FROM CUSTOMERS 2026-10-06.

                scripts/verify-3d-render.mjs renders the real scene in a
                headless browser and screenshots the framebuffer. The geometry
                is dimensionally correct - 230 x 432 x 460 mm, 372 meshes, every
                mesh added, zero page errors - and it passes all ten of its own
                automated checks. The captured frame is still not recognisable
                as a PC: a large featureless slab where the graphics card
                should be, several intersecting translucent planes, and no
                readable case, fan or memory module.

                Those checks cannot catch that, which is the point worth
                recording. They assert "not empty", "is lit" and "has N colour
                buckets" - all of which pass comfortably on an unusable frame.
                A green result that is quietly worse than intended is a failure.

                Verified LIVE on pctechguy.app/builder, so a customer could open
                this. It shipped nothing a customer could buy. The approved
                customer-facing visual, Storefront Render, stays available.

                The replacement is asset-driven: authored low-poly .glb parts
                with a separate interaction mesh for raycasting and a named
                RGB_Zone sub-mesh, rather than procedural primitives. Set
                config('builder.enable_3d_viewport') = true to restore it.
            --}}
            @if (config('builder.enable_3d_viewport'))

            <x-pctg.button
                variant="secondary"
                size="sm"
                @click.prevent="snapshotViewport()"
                x-show="viewportOpen"
                x-cloak
            >
                Save PNG
            </x-pctg.button>

            <x-pctg.button
                variant="secondary"
                size="sm"
                @click="toggleViewport()"
                x-text="viewportOpen ? 'Hide' : 'Show'"
            ></x-pctg.button>

            @endif

        </div>

    </div>

    <p class="mt-2 text-sm text-slate-400">
        @if (config('builder.enable_3d_viewport'))
            True-to-scale reference of your configured build
            <span class="text-slate-400/60">(dimensions from the live catalogue)</span>.
            <span class="text-slate-400/60">Updates as you change parts.</span>
        @else
            A photoreal render of your configured build, generated from the
            exact parts listed below.
        @endif
    </p>

    @if (config('builder.enable_3d_viewport'))
    <div
        x-show="viewportOpen"
        x-cloak
        class="mt-4"
        x-init="initViewport()"
    >
        <p
            class="mb-2 text-xs text-slate-500"
            x-text="viewportHint()"
        ></p>

        {{-- WebGL can be unavailable (old GPU, VM, locked-down browser). The
             old code threw out of the mount and left this black rectangle with
             no explanation, so the failure is stated in words instead. --}}
        <div
            class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200"
            x-show="viewportUnavailableReason"
            x-cloak
        >
            <p x-text="viewportUnavailableReason"></p>
        </div>

        {{-- three.js is fetched on first open, so the panel is briefly empty
             by design. A visible "loading" state is better than a black
             rectangle that looks like a broken renderer. --}}
        <div
            class="flex h-[380px] w-full items-center justify-center rounded-xl border border-slate-800 bg-[#0b0d12]"
            x-show="viewportLoading"
            x-cloak
        >
            <p class="text-sm text-slate-500">Loading the 3D view…</p>
        </div>

        <div
            id="pc-viewport"
            class="h-[380px] w-full overflow-hidden rounded-xl border border-slate-800 bg-[#0b0d12]"
            x-show="!viewportUnavailableReason && !viewportLoading"
        ></div>

        <p class="mt-2 text-xs text-slate-500" x-show="!viewportUnavailableReason && !viewportLoading">
            Drag to orbit · scroll to zoom · right-drag to pan
        </p>

        {{-- Parts whose real millimetre dimensions are unknown are labelled as
             unknown rather than omitted. A silent gap in a spec list reads as
             "we have this covered" when it means the opposite. --}}
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
                        x-text="part.dims
                            ? part.dims.x + ' × ' + part.dims.y + ' × ' + part.dims.z + ' mm'
                            : 'dimensions not published for this part'"
                    ></p>
                </div>
            </template>
        </div>

    @endif

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

        {{-- Any customer-facing image built from a third-party 3D asset carries
             its credit. Renders nothing when no asset is registered. --}}
        <div x-show="render3dUrl" x-cloak>
            @include('builder.partials.asset-credits')
        </div>
    </div>

    @if (config('builder.enable_3d_viewport'))
    <div
        class="mt-4 rounded-xl bg-slate-900/60 p-3 text-sm text-slate-400"
        x-show="!viewportOpen"
        x-cloak
    >
        Pick your parts and hit
        <span class="font-semibold text-white">Show</span>
        to preview the physical build at true scale — it re-renders itself every
        time you change a part.
        <template x-if="storefrontRenderAvailable">
            <span>
                Then
                <span class="font-semibold text-white">Storefront Render</span>
                turns the 3D geometry into a photoreal shot.
            </span>
        </template>
    </div>
    @endif

</x-pctg.card>