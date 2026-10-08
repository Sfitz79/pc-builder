<x-pctg.card>

    {{-- THE THREE-OPTION LAYER.

         One under budget, one closest to the figure asked for (within
         +/- GBP 150), and one best performance for the money. Produced by
         App\Services\BudgetOptions on the same SystemAssembler the featured
         range uses, so every part here already passes the component policy.

         A slot is OMITTED rather than filled when nothing lands inside its
         tolerance. An empty slot is honest; a filled one that quietly overshoots
         the budget the customer typed is not. --}}
    <template x-if="aiOptions && Object.keys(aiOptions).length">
        <div class="mb-8 border-b border-white/10 pb-8">
            <h3 class="text-sm font-semibold uppercase tracking-widest text-slate-400">
                Three ways to spend your budget
            </h3>

            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <template x-for="(opt, slot) in aiOptions" :key="slot">
                    <div class="flex flex-col rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <span class="rounded-full bg-slate-700/70 px-2.5 py-1 text-[11px] font-semibold text-slate-200"
                                  x-text="({
                                    under_budget: 'Under budget',
                                    at_budget: 'At your budget',
                                    best_value: 'Best for the money'
                                  })[slot] || slot"></span>
                            <span class="text-lg font-black text-white"
                                  x-text="'£' + Number(opt.total).toLocaleString(undefined, {minimumFractionDigits: 2})"></span>
                        </div>

                        <p class="mt-2 text-xs"
                           :class="opt.within_budget ? 'text-green-400' : 'text-amber-400'"
                           x-text="opt.within_budget
                               ? ('£' + Math.abs(opt.remaining).toFixed(0) + ' under your budget')
                               : ('£' + Math.abs(opt.difference).toFixed(0) + ' over your budget')"></p>

                        <ul class="mt-3 flex-1 space-y-1 text-xs text-slate-300">
                            <template x-for="c in (opt.components || [])" :key="c.type">
                                <li class="flex justify-between gap-2">
                                    <span class="text-slate-500" x-text="c.type"></span>
                                    <span class="truncate text-right" x-text="c.name"></span>
                                </li>
                            </template>
                        </ul>

                        <button type="button"
                                class="mt-4 rounded-lg bg-red-500 px-4 py-2 text-sm font-bold text-white hover:bg-red-600"
                                @click="applyAiOption(opt)">
                            Use this build
                        </button>
                    </div>
                </template>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                Every option is assembled from parts we stock and checked against our compatibility
                and component rules. Prices are today's catalogue estimate, confirmed at checkout.
            </p>
        </div>
    </template>

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
                            <x-pctg.badge variant="success" class="shrink-0">Included</x-pctg.badge>
                        </div>

                    </template>

                </div>

                <button
                    class="mt-4 w-full rounded-xl bg-red-500 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600"
                    @click="applyBuild(aiRecommendation)"
                    x-show="aiRecommendation.complete !== false"
                >
                    Build this Value Build
                </button>

                <div
                    class="mt-4 rounded-xl bg-amber-500/10 p-3 text-sm text-amber-300"
                    x-show="aiRecommendation.complete === false"
                >
                    This build is missing components and cannot be assembled. Please adjust your budget.
                </div>

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
                            <x-pctg.badge variant="success" class="shrink-0">Included</x-pctg.badge>
                        </div>

                    </template>

                </div>

                <button
                    class="mt-4 w-full rounded-xl bg-purple-500 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-600"
                    @click="applyBuild(aiIdealBuild)"
                    x-show="aiIdealBuild.complete !== false"
                >
                    Build this Ideal Build
                </button>

                <div
                    class="mt-4 rounded-xl bg-amber-500/10 p-3 text-sm text-amber-300"
                    x-show="aiIdealBuild.complete === false"
                >
                    This build is missing components and cannot be assembled.
                </div>

            </div>

        </template>

    </div>

    <template x-if="aiRecommendation && aiRecommendation.ai && aiRecommendation.ai.rationale">
        <div class="mt-4 rounded-xl border border-purple-500/20 bg-purple-500/5 p-4 text-sm text-purple-200">
            <p class="font-semibold">Why this build works</p>
            <p class="mt-1 text-purple-200/80" x-text="aiRecommendation.ai.rationale"></p>
        </div>
    </template>

    <template x-if="aiIdealBuild && aiIdealBuild.ai && aiIdealBuild.ai.rationale">
        <div class="mt-4 rounded-xl border border-red-500/20 bg-red-500/5 p-4 text-sm text-red-200">
            <p class="font-semibold">Why the ideal build goes further</p>
            <p class="mt-1 text-red-200/80" x-text="aiIdealBuild.ai.rationale"></p>
        </div>
    </template>

</x-pctg.card>
