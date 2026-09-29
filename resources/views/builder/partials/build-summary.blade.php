@php
    $buildDelivery = (float) config('pricing.build_delivery');
@endphp

<x-pctg.card>

    <x-pctg.section-heading
        title="Build Summary"
    />

    {{-- Announced pre-built load (deep link from /prebuilts). Prevents the
         screen changing silently. --}}
    <div
        x-show="prebuiltNotice"
        x-cloak
        class="mt-4 rounded-xl border border-red-500/20 bg-red-500/10 p-3"
    >
        <p class="text-sm font-bold text-red-300">
            Showing the <span x-text="prebuiltNotice"></span> pre-built
        </p>
        <p class="mt-1 text-xs text-slate-400">
            Every part below is loaded from that build. Swap anything before ordering.
        </p>
    </div>

    <div class="mt-6 space-y-4">

        <div class="flex justify-between">
            <span>System price</span>
            <span
                class="font-bold text-red-400"
                x-text="livePrice ? '£' + Number(livePrice.complete_price).toLocaleString() : '—'"
            ></span>
        </div>

        <p class="text-sm text-slate-400">
            One complete price for your system — includes build, burn test, cable
            management and warranty. Shown in full at checkout.
        </p>

        <div class="flex justify-between">
            <span>Assembly, burn test &amp; cable management</span>
            <span class="text-emerald-400">Included</span>
        </div>

        <hr class="border-slate-800">

        <div class="flex justify-between">

            <span class="font-bold">
                Total (with delivery)
            </span>

            <span
                class="text-2xl font-bold text-red-400"
                x-text="livePrice ? '£' + Number(livePrice.total).toLocaleString() : '—'"
            ></span>

        </div>

        <div
            class="rounded-xl bg-purple-500/5 p-3 text-sm text-slate-400"
            x-show="aiIdealBuild"
            x-cloak
        >
            <p class="font-semibold text-purple-300">
                Ideal (newest) build total:
                <span class="text-white" x-text="aiIdealBuild ? '£' + Number(aiIdealBuild.total).toLocaleString() : ''"></span>
            </p>
            <p class="mt-0.5">
                Showcasing the best of current generation parts with no budget limit.
            </p>
        </div>

        <div class="grid gap-3 pt-2">

            <div
                class="rounded-xl bg-emerald-500/10 p-3 text-center text-sm text-emerald-300"
                x-show="buildComplete"
                x-cloak
            >
                <p class="font-semibold">Build ready for checkout</p>
                <p class="mt-0.5 text-xs">Final price confirmed at checkout.</p>
            </div>

            <div
                class="rounded-xl bg-amber-500/10 p-3 text-sm text-amber-300"
                x-show="!buildComplete"
                x-cloak
            >
                <p class="font-semibold">
                    Your build is not complete.
                </p>
                <ul class="mt-1 list-inside list-disc space-y-0.5 text-amber-200/80">
                    <template x-for="category in missingComponents()" :key="category">
                        <li>
                            Missing:
                            <span x-text="categoryLabel(category)"></span>
                        </li>
                    </template>
                </ul>
            </div>

            <x-pctg.button
                variant="secondary"
                @click="checkout()"
                x-bind:disabled="!buildComplete"
                x-bind:class="buildComplete ? '' : 'opacity-50 cursor-not-allowed'"
            >
                Checkout
            </x-pctg.button>

            <x-pctg.button
                variant="secondary"
                @click="saveBuild()"
                x-bind:disabled="saving || !buildComplete"
                x-bind:class="buildComplete ? '' : 'opacity-50 cursor-not-allowed'"
            >
                <span x-text="saving ? 'Saving…' : 'Save Build'"></span>
            </x-pctg.button>

            <div
                class="rounded-xl bg-slate-900/60 p-3 text-sm text-slate-400"
                x-show="savedUrl"
                x-cloak
            >
                <p class="font-semibold text-emerald-400">
                    Build saved.
                </p>

                <div class="mt-1 flex flex-wrap gap-x-3">
                    <a
                        href="{{ route('builder.builds') }}"
                        class="hover:text-white"
                    >
                        View my builds →
                    </a>

                    <a
                        x-show="savedUrl"
                        :href="savedUrl"
                        target="_blank"
                        class="hover:text-white"
                    >
                        Public link
                    </a>
                </div>
            </div>

            <select
                class="pctg-input"
                x-model="selectedBuildId"
                @change="loadBuild(selectedBuildId)"
            >
                <option value="">
                    Load Saved Build…
                </option>

                <template x-for="build in savedBuilds" :key="build.id">
                    <option
                        :value="build.id"
                        x-text="build.name"
                    ></option>
                </template>
            </select>

            <div
                class="rounded-xl bg-slate-900/60 p-3 text-sm text-slate-400"
                x-show="loadedBuild"
                x-cloak
            >
                <p class="font-semibold text-white">
                    Loaded:
                    <span x-text="loadedBuild?.name"></span>
                </p>

                <p class="mt-0.5" x-text="loadedBuild ? '£' + Number(loadedBuild.total_price).toLocaleString() + ' total' : ''"></p>
            </div>

        </div>

    </div>

</x-pctg.card>
