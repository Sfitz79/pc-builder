@php
    $buildDelivery = (float) config('pricing.build_delivery');
@endphp

<x-pctg.card>

    <x-pctg.section-heading
        title="Build Summary"
    />

    <div class="mt-6 space-y-4">

        <div class="flex justify-between">
            <span>Components</span>
            <span x-text="'£' + buildCost().toLocaleString()"></span>
        </div>

        <div class="flex justify-between">
            <span>Build &amp; Delivery</span>
            <span>£{{ number_format($buildDelivery, 0) }}</span>
        </div>

        <div class="flex justify-between">
            <span>Assembly, burn test &amp; cable management</span>
            <span class="text-emerald-400">Included</span>
        </div>

        <hr class="border-slate-800">

        <div class="flex justify-between">

            <span class="font-bold">
                Total
            </span>

            <span
                class="text-2xl font-bold text-red-400"
                x-text="'£' + (buildCost() + {{ $buildDelivery }}).toLocaleString()"
            ></span>

        </div>

        <div class="grid gap-3 pt-2">

            <x-pctg.button
                variant="secondary"
                @click="checkout()"
            >
                Checkout
            </x-pctg.button>

            <x-pctg.button
                variant="secondary"
                @click="saveBuild()"
                x-bind:disabled="saving"
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
