@php
    /*
     * The PCTG BUSINESS wizard.
     *
     * This block replaces the gaming scenario picker when the builder was
     * entered from /business/build. It exists because the old single wizard
     * offered four hardcoded gaming buttons, so a buyer who had just read
     * "Enterprise Servers" and "NAS & Storage" on the Business page clicked
     * through to a page asking them to choose 🎮 Gaming or 🎥 Streaming.
     *
     * THE SCENARIOS ARE NOT TYPED HERE. They are read from config/workloads.php
     * (passed in as $workloads), which is the same list the recommendation
     * engine, the alias resolver and /business all read. A scenario this page
     * could offer but the engine could not honour would be a confident wrong
     * answer, so the two cannot drift.
     *
     * COPY: no prices, no benchmark figures, no stock claims - the same
     * restraint /business applies. Blurbs describe what constrains the build,
     * which is the thing a business buyer is actually shopping for.
     *
     * NO RESOLUTION SELECTOR. config/workloads.php sets
     * segments.business.resolutionRelevant = false, and businessGuardrails
     * ignores the resolution band. A workstation, a rack server and a NAS have
     * no relationship to "1080p / 1440p / 4K", and asking the question would
     * imply the answer changes the machine.
     */
    $isBusiness = ($segment ?? 'gaming') === 'business';
@endphp

@if ($isBusiness)
    <x-pctg.hero>

        <x-pctg.badge variant="business">
            🏢 PCTG Business — Technology That Means Business
        </x-pctg.badge>

        <h1 class="mt-6 text-4xl font-black md:text-6xl">
            Spec Your Workstation
            <span class="text-biz-primary">or Business System</span>
        </h1>

        <p class="mt-4 max-w-2xl text-slate-400">
            Pick the work it has to do. We spec the parts around that workload and
            your budget, and we will tell you if the answer is going to be wrong.
        </p>

        {{-- =================================================================
             THE SME RANGE: business size -> job type -> system type.

             This is the front door, above the workload grid. A sole trader and
             a hospital imaging department were both previously offered
             "Creative Studio" as though they wanted the same machine, at the
             same money. Businesses do not buy by software workload; they buy by
             what they do all day.

             The hardware numbers behind each system type are in config/business.php
             and are grounded in UK SME guidance, not invented here - the source
             is documented in that file.

             Every card states plainly whether we can build it from what we
             stock. "Not stocked yet" is shown, not hidden, and names the part.
             ================================================================= --}}
        @if (! empty($systemTypeGroups))
            <div class="mt-10">
                <h2 class="text-2xl font-black">Start with the size of your business</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-400">
                    Then pick what the machine is actually for. We will only offer
                    a one-click build where we stock every part it needs.
                </p>

                {{-- THE DISCLOSURE, stated once, before any competitor link.

                     Without it a panel of supplier links under our own branding
                     reads as though we sell those machines, or that we have
                     checked them and found them fine. Neither is true. We do not
                     earn on these links and we have not priced them. --}}
                <p class="mt-3 max-w-3xl rounded-xl border border-white/5 bg-white/[0.03] p-3 text-xs leading-relaxed text-slate-400">
                    Where a system is marked <span class="text-amber-300/90">not stocked</span>,
                    the links below point at the retailers who do sell it. Those
                    are not our products, they are not on our price list, and we
                    do not earn from the links. We have not checked their prices
                    today &mdash; check them yourself, or ask us to spec a build
                    from the parts we can actually supply.
                </p>

                @foreach ($systemTypeGroups as $sizeKey => $size)
                    <div class="mt-7">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h3 class="text-lg font-bold text-biz-primary">{{ $size['label'] }}</h3>
                            <span class="text-xs uppercase tracking-widest text-slate-500">{{ $size['headcount'] ?? '' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-400">{{ $size['blurb'] ?? '' }}</p>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($size['systems'] as $system)
                                @php
                                    $resolved = \App\Services\BusinessSystemType::class;
                                    $detail = app($resolved)->find($system['slug']);
                                    $canBuild = $detail['buildable'] ?? false;
                                    $band = $detail['band'] ?? [];
                                @endphp
                                <div class="rounded-2xl border border-white/5 bg-biz-surface p-5">
                                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">
                                        {{ $system['job'] ?? '' }}
                                    </p>
                                    <p class="mt-1 font-bold">{{ $system['label'] ?? $system['slug'] }}</p>
                                    <p class="mt-2 text-sm leading-relaxed text-slate-400">
                                        {{ $system['blurb'] ?? '' }}
                                    </p>

                                    @if ($band)
                                        <p class="mt-3 text-xs text-slate-500">
                                            Typically &pound;{{ number_format($band[0]) }}&ndash;{{ number_format($band[1]) }}
                                            fully built
                                        </p>
                                    @endif

                                    @if ($canBuild)
                                        <a
                                            href="{{ route('business.build', ['purpose' => $system['workload'] ?? null, 'type' => $system['slug']]) }}"
                                            class="mt-4 inline-flex items-center gap-1 text-sm font-bold text-biz-accent hover:underline"
                                        >
                                            Configure this
                                            <span aria-hidden="true">&rarr;</span>
                                        </a>
                                    @else
                                        {{-- Named, with the reason. Not hidden. --}}
                                        <p class="mt-4 text-xs leading-relaxed text-amber-300/90">
                                            {{ $detail['unavailable_reason'] ?? 'Not available to configure online yet.' }}
                                        </p>
                                        <a
                                            href="{{ route('support') }}?workload={{ urlencode($system['slug']) }}"
                                            class="mt-2 inline-flex items-center gap-1 text-sm font-bold text-biz-accent hover:underline"
                                        >
                                            Ask us to spec it
                                            <span aria-hidden="true">&rarr;</span>
                                        </a>

                                        {{-- SIGNPOST, not a shop.
                                             Shown only when we cannot build it, and
                                             only alongside the reason why. Plain
                                             links, no affiliate, and no price
                                             quoted on someone else's behalf. --}}
                                        @if (! empty($detail['sourcing']))
                                            <div class="mt-4 border-t border-white/5 pt-3">
                                                <p class="text-xs text-slate-500">
                                                    {{ $detail['sourcing']['blurb'] ?? '' }}
                                                </p>
                                                <ul class="mt-2 space-y-1">
                                                    @foreach ($detail['sourcing']['links'] as $link)
                                                        <li>
                                                            <a
                                                                href="{{ $link['url'] }}"
                                                                target="_blank"
                                                                rel="noopener nofollow"
                                                                class="text-xs font-semibold text-biz-accent hover:underline"
                                                            >
                                                                {{ $link['name'] }}
                                                                <span class="text-slate-500" aria-hidden="true">&#8599;</span>
                                                                <span class="sr-only">(opens in a new tab)</span>
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <h2 class="mt-12 text-2xl font-black">Or describe the job it has to do</h2>
        <p class="mt-2 max-w-2xl text-sm text-slate-400">
            If you already know what the machine is for, pick it here.
        </p>

        {{-- The scenario grid. Selected state is driven by the seeded Alpine
             `purpose`, so a deep link such as /business/build?purpose=nas
             arrives with the right card already ringed in blue. --}}
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($workloads as $slug => $workload)
                <button
                    type="button"
                    class="pctg-scenario-card"
                    :class="purpose === @js($slug) ? 'pctg-scenario-card-active' : ''"
                    @click="purpose = @js($slug)"
                    :aria-pressed="(purpose === @js($slug)).toString()"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-bold">{{ $workload['label'] ?? $slug }}</span>
                        <span
                            x-show="purpose === @js($slug)"
                            x-cloak
                            class="text-biz-accent"
                            aria-hidden="true"
                        >✓</span>
                    </span>

                    <span class="mt-2 block text-sm font-normal leading-relaxed text-slate-400">
                        {{ $workload['blurb'] ?? '' }}
                    </span>
                </button>
            @empty
                <p class="text-sm text-slate-400 sm:col-span-2 lg:col-span-3">
                    None of our professional workloads are stocked for online
                    configuration right now. Talk to us and we will spec one with
                    you.
                </p>
            @endforelse
        </div>

        {{-- WHAT WE CANNOT BUILD YET
             Not hidden, and not quietly built anyway. These are real products
             we want to sell, and the honest position is that the catalogue does
             not hold the parts yet - so the button is a conversation instead of
             a quote we cannot honour.

             The alternative, which this replaced, was offering a build button
             that produced an RTX 5070 in an RGB case for "NAS & Storage".
             Naming the missing part is more useful to the customer than a
             machine that cannot do the job. --}}
        @if (count($specOnlyWorkloads))
            <div class="mt-10 rounded-2xl border border-white/5 bg-biz-surface p-6">
                <h2 class="text-lg font-bold">Not stocked for online build yet</h2>

                <p class="mt-2 max-w-2xl text-sm text-biz-text-secondary">
                    We build these, but we do not yet stock every part they need,
                    so we will not quote you one online. Tell us the workload and
                    we will spec and price it properly.
                </p>

                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($specOnlyWorkloads as $slug => $workload)
                        @php
                            $verdict = $workload['verdict'] ?? ['missing_labels' => []];
                            $missing = $verdict['missing_labels'] ?? [];
                        @endphp
                        <div class="rounded-xl border border-white/5 bg-biz-background p-4">
                            <p class="font-bold">{{ $workload['label'] ?? $slug }}</p>

                            <p class="mt-2 text-xs leading-relaxed text-biz-text-secondary">
                                @if ($missing)
                                    Still needs: {{ implode(', ', $missing) }}.
                                @else
                                    Not available to configure online yet.
                                @endif
                            </p>

                            <a
                                href="{{ route('support') }}?workload={{ urlencode($slug) }}"
                                class="mt-3 inline-flex items-center gap-1 text-sm font-bold text-biz-accent hover:underline"
                            >
                                Talk to us about {{ strtolower($workload['label'] ?? $slug) }}
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

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

                {{-- Same measured floor as the gaming builder, taken from the
                     LOWEST band. It is the cheapest complete machine we can
                     genuinely build, which is true regardless of workload, and
                     it is deliberately NOT labelled with a resolution: we have
                     not measured a business band, so quoting one would be a
                     number we cannot defend. --}}
                <input
                    type="range"
                    class="mt-3 w-full accent-blue-600"
                    aria-label="Build budget slider"
                    :min="budgetFloor()"
                    :max="businessCeiling()"
                    step="10"
                    :value="budget < budgetFloor() ? budgetFloor() : budget"
                    @input="budget = Number($event.target.value); applyBudgetFloor();"
                >

                <div class="mt-1 flex items-center justify-between text-xs text-slate-400">
                    <span x-text="money(budgetFloor())"></span>
                    <span x-text="money(businessCeiling())"></span>
                </div>
            </div>

            <div class="flex items-end">
                <x-pctg.button @click="generateBuild()" class="w-full">
                    <span x-show="!loading">Generate Business Build</span>
                    <span x-show="loading">Generating…</span>
                </x-pctg.button>
            </div>

        </div>

        {{-- A business build has no resolution, so the resolver must not be
             sent one. Passing the stale '1440P' default would let the engine
             apply a gaming tier ladder and a gaming band clamp to a server
             quote. --}}
        <p class="mt-4 text-xs text-slate-500">
            No display resolution is asked for here on purpose — it changes a
            gaming build and nothing about a workstation, a server or a NAS.
        </p>

        @include('builder.partials.budget-notice')

    </x-pctg.hero>

@else
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

    @include('builder.partials.budget-notice')

</x-pctg.hero>

@endif
