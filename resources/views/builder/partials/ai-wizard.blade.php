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

        <input
            type="number"
            placeholder="Budget £"
            class="pctg-input"
            x-model.number="budget"
        >

        <select class="pctg-input" x-model="resolution">

            <option>1080P</option>
            <option>1440P</option>
            <option>4K</option>

        </select>

        <x-pctg.button @click="generateBuild()">
            <span x-show="!loading">Generate AI Build</span>
            <span x-show="loading">Generating…</span>
        </x-pctg.button>

    </div>

    <p
        class="mt-4 text-sm font-medium text-emerald-400"
        x-show="aiRecommendation && aiRecommendation.total && !loading"
        x-cloak
    >
        ✓ Build generated — £<span x-text="aiRecommendation ? aiRecommendation.total.toLocaleString() : ''"></span> total · review your parts below
    </p>

</x-pctg.hero>
