<x-pctg.card>

    <x-pctg.section-heading
        title="Build Health"
    />

    <div class="mt-5 flex justify-center">

        <div
            class="flex h-40 w-40 items-center justify-center rounded-full border-8 text-4xl font-bold"
            :class="healthScore() >= 80 ? 'border-emerald-500' : (healthScore() >= 60 ? 'border-amber-500' : 'border-red-500')"
            x-text="healthScore()"
        ></div>

    </div>

    <p class="mt-4 text-center text-slate-400">
        <span x-text="healthLabel()"></span>
    </p>

</x-pctg.card>
