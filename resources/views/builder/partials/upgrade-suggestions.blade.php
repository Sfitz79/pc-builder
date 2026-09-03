<x-pctg.card>

    <x-pctg.section-heading
        title="Upgrade Path to Ideal"
    />

    <template x-if="aiIdealBuild">
        <div class="mt-6 space-y-3">

            <template x-for="(ideal, category) in aiIdealBuild.components" :key="category">
                <template x-if="selected[category] && ideal && selected[category].id !== ideal.id">
                    <div class="pctg-card">
                        <div class="flex justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs uppercase text-slate-500" x-text="category"></p>
                                <p class="truncate text-sm">
                                    Upgrade to
                                    <span class="font-semibold text-white" x-text="ideal.name"></span>
                                </p>
                            </div>
                            <span class="shrink-0 text-sm text-green-400" x-text="'£' + (ideal.price - selected[category].price).toLocaleString()"></span>
                        </div>
                    </div>
                </template>
            </template>

            <template x-if="allIdealApplied()">
                <p class="text-sm text-emerald-400">You're already running the ideal build.</p>
            </template>

        </div>
    </template>

    <template x-if="!aiIdealBuild">
        <div class="mt-6">
            <p class="text-sm text-slate-400">
                Generate a build to see how you can push it to the latest gen.
            </p>
        </div>
    </template>

</x-pctg.card>
