<x-pctg.card>

    <x-pctg.section-heading
        title="AI Recommended Build"
        description="Optimized for your budget, purpose and resolution."
    />

    <div class="mt-6 grid gap-4 md:grid-cols-2">

        <template x-if="aiRecommendation">

            <template x-for="(item, category) in aiRecommendation.components" :key="category">

                <div class="pctg-card-hover">

                    <div class="flex justify-between">

                        <div>
                            <h3 class="text-lg font-semibold" x-text="item.name"></h3>

                            <p class="text-sm text-slate-400" x-text="item.tags || ''"></p>
                        </div>

                        <span class="font-bold text-red-400" x-text="'£' + item.price"></span>

                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <span
                            class="rounded-full bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-400"
                            x-text="category"
                        ></span>
                    </div>

                </div>

            </template>

        </template>

        <template x-if="!aiRecommendation">

            <div class="pctg-card-hover">
                <div class="flex justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">AMD Ryzen 9700X</h3>
                        <p class="text-sm text-slate-400">8 Core / 16 Thread</p>
                    </div>
                    <span class="font-bold text-red-400">£329</span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="rounded-full bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-400">AI Pick</span>
                </div>
            </div>

            <div class="pctg-card-hover">
                <div class="flex justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">RTX 5070 Ti</h3>
                        <p class="text-sm text-slate-400">16GB GDDR7</p>
                    </div>
                    <span class="font-bold text-red-400">£799</span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="rounded-full bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-400">4K Gaming</span>
                </div>
            </div>

        </template>

        <template x-if="aiRecommendation && aiRecommendation.ai && aiRecommendation.ai.rationale">
            <div class="mt-4 rounded-xl border border-purple-500/20 bg-purple-500/5 p-4 text-sm text-purple-200">
                <p class="font-semibold">Gemini insight</p>
                <p class="mt-1 text-purple-200/80" x-text="aiRecommendation.ai.rationale"></p>
            </div>
        </template>

    </div>

</x-pctg.card>
