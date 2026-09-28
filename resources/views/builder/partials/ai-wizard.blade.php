<x-pctg.hero>

    <x-pctg.badge>
        🤖 AI Powered Builder
    </x-pctg.badge>

    <h1 class="mt-6 text-4xl font-black md:text-6xl">
        Build Your Next PC

        <span class="text-red-500">
            With AI
        </span>
    </h1>

    <p class="mt-4 max-w-2xl text-slate-400">
        Tell us your budget and intended use.
        PCTG AI recommends optimized
        compatible components instantly.
    </p>

    <div class="mt-10 grid gap-4 md:grid-cols-4">

        <button
            class="pctg-card-hover text-center"
            :class="purpose === 'gaming' ? 'ring-2 ring-red-500/70 border-red-500/70' : ''"
            @click="purpose = 'gaming'"
        >
            <span x-show="purpose === 'gaming'" x-cloak class="mr-1 text-red-500">✓</span>🎮 Gaming
        </button>

        <button
            class="pctg-card-hover text-center"
            :class="purpose === 'streaming' ? 'ring-2 ring-red-500/70 border-red-500/70' : ''"
            @click="purpose = 'streaming'"
        >
            <span x-show="purpose === 'streaming'" x-cloak class="mr-1 text-red-500">✓</span>🎥 Streaming
        </button>

        <button
            class="pctg-card-hover text-center"
            :class="purpose === 'creation' ? 'ring-2 ring-red-500/70 border-red-500/70' : ''"
            @click="purpose = 'creation'"
        >
            <span x-show="purpose === 'creation'" x-cloak class="mr-1 text-red-500">✓</span>🎨 Content Creation
        </button>

        <button
            class="pctg-card-hover text-center"
            :class="purpose === 'ai' ? 'ring-2 ring-red-500/70 border-red-500/70' : ''"
            @click="purpose = 'ai'"
        >
            <span x-show="purpose === 'ai'" x-cloak class="mr-1 text-red-500">✓</span>🤖 AI Development
        </button>

    </div>

    <div class="mt-8 grid gap-4 md:grid-cols-3">

        <div>
            <label
                for="pctg-budget"
                class="mb-1 block text-sm font-medium text-slate-300"
            >
                Your budget
            </label>

            <input
                id="pctg-budget"
                type="number"
                placeholder="Budget £"
                class="pctg-input"
                :min="budgetFloor()"
                x-model.number="budget"
                @blur="applyBudgetFloor()"
                @keydown.enter="applyBudgetFloor()"
            >

            {{-- The slider starts at the cheapest machine we can genuinely
                 build, so every value it can reach really does produce a PC at
                 the chosen resolution (boss directive 2026-09-28). --}}
            <input
                type="range"
                class="mt-3 w-full accent-red-500"
                aria-label="Build budget slider"
                :min="budgetFloor()"
                :max="currentBand().max"
                step="10"
                :value="budget < budgetFloor() ? budgetFloor() : budget"
                @input="budget = Number($event.target.value); applyBudgetFloor();"
            >

            <div class="mt-1 flex items-center justify-between text-xs text-slate-400">
                <span x-text="money(budgetFloor())"></span>
                <span x-text="money(currentBand().max)"></span>
            </div>
        </div>

        <div>
            <label
                for="pctg-resolution"
                class="mb-1 block text-sm font-medium text-slate-300"
            >
                Screen resolution
            </label>

            <select id="pctg-resolution" class="pctg-input" x-model="resolution">

                <option>1080P</option>
                <option>1440P</option>
                <option>4K</option>

            </select>
        </div>

        <div class="flex items-end">
            <x-pctg.button @click="generateBuild()" class="w-full">
                <span x-show="!loading">Generate AI Build</span>
                <span x-show="loading">Generating…</span>
            </x-pctg.button>
        </div>

    </div>

    {{-- Below the honest minimum: say why, in plain English, and offer the
         part-new/part-used route on WhatsApp rather than leaving the customer
         at a dead end. --}}
    <div
        x-show="budgetNotice"
        x-cloak
        x-transition
        class="mt-4 rounded-xl border border-amber-500/50 bg-amber-500/10 p-4"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-start gap-3">
            <span class="mt-0.5 text-lg" aria-hidden="true">⚠️</span>

            <div class="flex-1">
                <p
                    class="font-semibold text-amber-200"
                    x-text="budgetNotice
                        ? 'We have moved your budget to ' + money(budgetNotice.min)
                        : ''"
                ></p>

                <p
                    class="mt-1 text-sm text-amber-100/90"
                    x-text="budgetNotice ? budgetNotice.message : ''"
                ></p>

                <div
                    x-show="budgetNotice && budgetNotice.hybrid"
                    class="mt-3 rounded-lg border border-amber-500/30 bg-black/20 p-3"
                >
                    <p
                        class="text-sm font-semibold text-white"
                        x-text="budgetNotice && budgetNotice.hybrid
                            ? budgetNotice.hybrid.headline
                            : ''"
                    ></p>

                    <p
                        class="mt-1 text-sm text-slate-300"
                        x-text="budgetNotice && budgetNotice.hybrid
                            ? budgetNotice.hybrid.message
                            : ''"
                    ></p>

                    <a
                        class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-400"
                        :href="budgetNotice && budgetNotice.hybrid
                            ? budgetNotice.hybrid.whatsapp_url
                            : '#'"
                        target="_blank"
                        rel="noopener"
                    >
                        <span aria-hidden="true">💬</span>
                        Message us on WhatsApp
                    </a>
                </div>

                <button
                    type="button"
                    class="mt-3 text-sm text-slate-400 underline hover:text-slate-200"
                    @click="dismissBudgetNotice()"
                >
                    Got it
                </button>
            </div>
        </div>
    </div>

    <p
        class="mt-4 text-sm font-medium text-emerald-400"
        x-show="aiRecommendation && aiRecommendation.total && !loading"
        x-cloak
    >
        ✓ Build generated — £<span x-text="aiRecommendation ? aiRecommendation.total.toLocaleString() : ''"></span> total · review your parts below
    </p>

</x-pctg.hero>
