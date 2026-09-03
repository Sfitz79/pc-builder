<x-pctg.card>

    <x-pctg.section-heading
        title="AI Recommended Builds"
        description="Best value for your budget, plus the ideal newest build."
    />

    <template x-if="!aiRecommendation">
        <p class="mt-4 text-sm text-slate-400">
            Set your budget, purpose and resolution above, then hit "Generate AI Build".
        </p>
    </template>

    <div class="mt-6 grid gap-4 md:grid-cols-2">

        {{-- Best value build for budget --}}
        <template x-if="aiRecommendation">

            <div class="rounded-2xl border border-red-500/30 bg-red-500/5 p-4">

                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-red-400">
                        <span x-text="'£' + Number(aiRecommendation.total).toLocaleString()"></span>
                        <span class="text-sm font-normal text-slate-400">Best Value Build</span>
                    </h3>
                    <span class="rounded-full bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-400">Budget</span>
                </div>

                <div class="mt-4 space-y-2">

                    <template x-for="(item, category) in aiRecommendation.components" :key="category">

                        <div class="flex items-center justify-between gap-2 text-sm">
                            <div class="min-w-0">
                                <span class="text-xs uppercase text-slate-500" x-text="category"></span>
                                <p class="truncate font-medium" x-text="item.name"></p>
                            </div>
                            <span class="shrink-0 font-semibold text-red-400" x-text="'£' + item.price"></span>
                        </div>

                    </template>

                </div>

                <button
                    class="mt-4 w-full rounded-xl bg-red-500 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600"
                    @click="applyBuild(aiRecommendation)"
                >
                    Build this Value Build
                </button>

            </div>

        </template>

        {{-- Ideal newest build --}}
        <template x-if="aiIdealBuild">

            <div class="rounded-2xl border border-purple-500/30 bg-purple-500/5 p-4">

                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-purple-400">
                        <span x-text="'£' + Number(aiIdealBuild.total).toLocaleString()"></span>
                        <span class="text-sm font-normal text-slate-400">Ideal Build</span>
                    </h3>
                    <span class="rounded-full bg-purple-500/10 px-3 py-1 text-xs font-semibold text-purple-400">Newest</span>
                </div>

                <div class="mt-4 space-y-2">

                    <template x-for="(item, category) in aiIdealBuild.components" :key="category">

                        <div class="flex items-center justify-between gap-2 text-sm">
                            <div class="min-w-0">
                                <span class="text-xs uppercase text-slate-500" x-text="category"></span>
                                <p class="truncate font-medium" x-text="item.name"></p>
                            </div>
                            <span class="shrink-0 font-semibold text-purple-400" x-text="'£' + item.price"></span>
                        </div>

                    </template>

                </div>

                <button
                    class="mt-4 w-full rounded-xl bg-purple-500 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-600"
                    @click="applyBuild(aiIdealBuild)"
                >
                    Build this Ideal Build
                </button>

            </div>

        </template>

    </div>

    <template x-if="aiRecommendation && aiRecommendation.ai && aiRecommendation.ai.rationale">
        <div class="mt-4 rounded-xl border border-purple-500/20 bg-purple-500/5 p-4 text-sm text-purple-200">
            <p class="font-semibold">Gemini insight</p>
            <p class="mt-1 text-purple-200/80" x-text="aiRecommendation.ai.rationale"></p>
        </div>
    </template>

</x-pctg.card>
