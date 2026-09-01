<x-app-layout title="Welcome">

    @include('partials.intro')

    {{-- Hero --}}
    <section class="relative">
        <x-pctg.hero class="pctg-landing-reveal mb-12">
            <span class="pctg-landing-reveal inline-flex items-center gap-2 rounded-full border border-red-500/20 bg-red-500/10 px-4 py-2 text-sm font-semibold text-red-300" style="animation-delay: 0ms">
                <x-pctg.icon name="sparkles" class="h-3.5 w-3.5" /> AI Powered PC Builder
            </span>

            <h1 class="pctg-landing-reveal mt-8 text-5xl font-black leading-none md:text-7xl" style="animation-delay: 120ms">
                Build Your
                <span class="block pctg-pulse text-red-500">
                    Dream Gaming PC
                </span>
                In Minutes
            </h1>

            <p class="pctg-landing-reveal mt-8 max-w-2xl text-lg text-slate-400" style="animation-delay: 240ms">
                Professional AI recommendations, compatibility checking, FPS estimates and
                expert UK-built gaming systems. Tell us what you want to play, tell us your
                budget — we'll build the perfect PC.
            </p>

            <div class="pctg-landing-reveal mt-10 flex flex-wrap gap-4" style="animation-delay: 360ms">
                <x-pctg.button href="/builder" variant="primary" size="lg">
                    <x-pctg.icon name="sparkles" class="h-5 w-5" /> Start Building
                </x-pctg.button>
                <x-pctg.button href="#features" variant="secondary" size="lg">
                    Learn More
                </x-pctg.button>
            </div>

            <span class="pctg-particle absolute bottom-10 left-1/4 h-2 w-2 rounded-full bg-red-500/60" aria-hidden="true"></span>
            <span class="pctg-particle absolute right-1/4 top-16 h-1.5 w-1.5 rounded-full bg-red-400/50" style="animation-delay: 1.2s" aria-hidden="true"></span>
            <span class="pctg-particle absolute bottom-1/3 right-10 h-2 w-2 rounded-full bg-red-500/50" style="animation-delay: 2.4s" aria-hidden="true"></span>
        </x-pctg.hero>
    </section>

    {{-- Animated statistics --}}
    <section class="mb-12">
        <div class="grid grid-cols-2 gap-4 pctg-reveal xl:grid-cols-4">
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">
                    <span data-pctg-count="500" data-pctg-suffix="+">0+</span>
                </div>
                <div class="mt-3 text-slate-400">Systems Built</div>
            </div>
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">
                    <span data-pctg-count="4.9" data-pctg-decimals="1" data-pctg-suffix="★">0.0★</span>
                </div>
                <div class="mt-3 text-slate-400">Customer Rating</div>
            </div>
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">
                    <span data-pctg-count="28" data-pctg-suffix="+">0+</span>
                </div>
                <div class="mt-3 text-slate-400">Years Experience</div>
            </div>
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">
                    <span data-pctg-count="99.9" data-pctg-decimals="1" data-pctg-suffix="%">0.0%</span>
                </div>
                <div class="mt-3 text-slate-400">Compatibility Success</div>
            </div>
        </div>
    </section>

    {{-- AI features --}}
    <section id="features" class="mb-12">
        <div class="mb-10 text-center pctg-reveal">
            <h2 class="text-4xl font-black">Powered By AI</h2>
            <p class="mx-auto mt-4 max-w-3xl text-slate-400">
                Let PCTG AI build the perfect PC for your budget, games and performance goals.
            </p>
        </div>

        <div class="grid gap-4 pctg-reveal md:grid-cols-3" style="--reveal-delay: 120ms">
            <x-pctg.hover-card>
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-pctg-primary/10 text-pctg-primary-hover ring-1 ring-pctg-primary/30">
                    <x-pctg.icon name="cpu" class="h-6 w-6" />
                </div>
                <h3 class="text-lg font-bold">AI Recommendations</h3>
                <p class="mt-3 text-sm leading-relaxed text-slate-400">
                    Tell us your budget and use case. We recommend the ideal hardware for maximum frames per pound.
                </p>
                <x-pctg.stat-tag class="mt-5">Powered by PCTG AI</x-pctg.stat-tag>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-pctg-warning/10 text-pctg-warning ring-1 ring-pctg-warning/30">
                    <x-pctg.icon name="gauge" class="h-6 w-6" />
                </div>
                <h3 class="text-lg font-bold">FPS Estimates</h3>
                <p class="mt-3 text-sm leading-relaxed text-slate-400">
                    View expected gaming performance for Fortnite, Warzone and 100+ titles before you buy.
                </p>
                <x-pctg.stat-tag class="mt-5">Live predictions</x-pctg.stat-tag>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-pctg-success/10 text-pctg-success ring-1 ring-pctg-success/30">
                    <x-pctg.icon name="shield-check" class="h-6 w-6" />
                </div>
                <h3 class="text-lg font-bold">Compatibility Checks</h3>
                <p class="mt-3 text-sm leading-relaxed text-slate-400">
                    Socket, wattage, clearance and BIOS checks run on every build before you order.
                </p>
                <x-pctg.stat-tag class="mt-5">100% verified</x-pctg.stat-tag>
            </x-pctg.hover-card>
        </div>
    </section>

    {{-- Featured systems --}}
    <section id="systems" class="mb-12">
        <div class="mb-10 text-center pctg-reveal">
            <h2 class="text-4xl font-black">Featured Systems</h2>
            <p class="mx-auto mt-4 max-w-3xl text-slate-400">
                Hand-tuned pre-builts with the PCTG AI build under the hood.
            </p>
        </div>

        <div class="grid gap-4 pctg-reveal lg:grid-cols-3" style="--reveal-delay: 120ms">
            <x-pctg.hover-card>
                <span class="pctg-badge bg-red-500/10 text-red-300">Gaming</span>
                <h3 class="mt-5 text-2xl font-black">Frostbyte XT</h3>
                <ul class="mt-4 space-y-2 text-sm text-slate-400">
                    <li><strong class="text-white">CPU:</strong> AMD Ryzen 7 9700X</li>
                    <li><strong class="text-white">GPU:</strong> RTX 5070 Ti</li>
                    <li><strong class="text-white">Performance:</strong> 220+ FPS Fortnite @ 1440P</li>
                </ul>
                <div class="mt-6">
                    <x-pctg.button href="/builder" variant="secondary">Customise this build</x-pctg.button>
                </div>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <span class="pctg-badge bg-purple-500/10 text-purple-300">4K Ultra</span>
                <h3 class="mt-5 text-2xl font-black">Arctic Ghost</h3>
                <ul class="mt-4 space-y-2 text-sm text-slate-400">
                    <li><strong class="text-white">CPU:</strong> AMD Ryzen 7 9800X3D</li>
                    <li><strong class="text-white">GPU:</strong> RTX 5080</li>
                    <li><strong class="text-white">Performance:</strong> 4K ultra gaming ready</li>
                </ul>
                <div class="mt-6">
                    <x-pctg.button href="/builder" variant="secondary">Customise this build</x-pctg.button>
                </div>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <span class="pctg-badge bg-yellow-500/10 text-yellow-300">Streamer</span>
                <h3 class="mt-5 text-2xl font-black">Stormbyte</h3>
                <ul class="mt-4 space-y-2 text-sm text-slate-400">
                    <li><strong class="text-white">CPU:</strong> AMD Ryzen 7 9700X</li>
                    <li><strong class="text-white">GPU:</strong> RTX 5070 (NVENC)</li>
                    <li><strong class="text-white">Performance:</strong> 1440P gaming + 1080P stream</li>
                </ul>
                <div class="mt-6">
                    <x-pctg.button href="/builder" variant="secondary">Customise this build</x-pctg.button>
                </div>
            </x-pctg.hover-card>
        </div>
    </section>

    {{-- Trust strip --}}
    <section class="mb-12">
        <x-pctg.glass class="p-8 md:p-10">
            <div class="grid gap-6 pctg-reveal sm:grid-cols-2 lg:grid-cols-5">
                <div class="flex items-center gap-3">
                    <x-pctg.icon name="check-circle" class="h-6 w-6 text-pctg-success" />
                    <span class="font-medium">Burn Tested</span>
                </div>
                <div class="flex items-center gap-3">
                    <x-pctg.icon name="shield-check" class="h-6 w-6 text-pctg-success" />
                    <span class="font-medium">UK Based</span>
                </div>
                <div class="flex items-center gap-3">
                    <x-pctg.icon name="cpu" class="h-6 w-6 text-pctg-success" />
                    <span class="font-medium">Expert Built</span>
                </div>
                <div class="flex items-center gap-3">
                    <x-pctg.icon name="box" class="h-6 w-6 text-pctg-success" />
                    <span class="font-medium">Warranty Included</span>
                </div>
                <div class="flex items-center gap-3">
                    <x-pctg.icon name="check" class="h-6 w-6 text-pctg-success" />
                    <span class="font-medium">Compatibility Guaranteed</span>
                </div>
            </div>
        </x-pctg.glass>
    </section>

    {{-- AI Builder showcase (interactive) --}}
    <section id="ai-builder-demo" class="mb-12" data-builder-demo>
        <div class="mb-10 text-center pctg-reveal">
            <h2 class="text-4xl font-black">See The AI Builder In Action</h2>
            <p class="mx-auto mt-4 max-w-3xl text-slate-400">
                Pick a use case, set a budget and hit generate. The same engine that powers the builder —
                interactive right here, no account needed.
            </p>
        </div>

        <x-pctg.glass class="p-8 md:p-10">
            <div class="grid gap-10 lg:grid-cols-2">
                {{-- Controls --}}
                <div class="pctg-reveal">
                    <h3 class="text-lg font-bold">What are you building?</h3>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <button type="button" data-demo-case="gaming" class="demo-case-btn is-active">🎮 Gaming</button>
                        <button type="button" data-demo-case="streaming" class="demo-case-btn">🎥 Streaming</button>
                        <button type="button" data-demo-case="creation" class="demo-case-btn">🎨 Content Creation</button>
                        <button type="button" data-demo-case="ai" class="demo-case-btn">🤖 AI Development</button>
                    </div>

                    <h3 class="mt-8 text-lg font-bold">Budget</h3>

                    <input
                        type="range"
                        min="800"
                        max="3000"
                        step="50"
                        value="1500"
                        data-demo-budget
                        class="mt-4 w-full accent-red-500"
                        aria-label="Build budget"
                    >

                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-slate-400">£800</span>
                        <span class="font-bold text-white" data-demo-budget-label>£1,500</span>
                        <span class="text-slate-400">£3,000</span>
                    </div>

                    <div class="mt-8">
                        <x-pctg.button data-demo-generate>
                            <x-pctg.icon name="sparkles" class="h-5 w-5" /> Generate Build
                        </x-pctg.button>
                    </div>
                </div>

                {{-- Result panel (rendered by pctg-landing-demos.js) --}}
                <div data-demo-result class="rounded-2xl border border-slate-800 bg-[#12151c] p-6 pctg-reveal"></div>
            </div>
        </x-pctg.glass>
    </section>

    {{-- FPS estimator (interactive) --}}
    <section id="fps-demo" class="mb-12" data-fps-demo>
        <div class="mb-10 text-center pctg-reveal">
            <h2 class="text-4xl font-black">How Many FPS Will You Get?</h2>
            <p class="mx-auto mt-4 max-w-3xl text-slate-400">
                Mix and match CPUs and GPUs to see expected performance in the games that matter most.
            </p>
        </div>

        <x-pctg.glass class="p-8 md:p-10">
            <div class="grid gap-8 pctg-reveal lg:grid-cols-3">
                {{-- Controls --}}
                <div class="space-y-6">
                    <div>
                        <label for="demo-cpu" class="mb-2 block text-sm font-semibold text-slate-400">CPU</label>
                        {{-- To use the live /builder/fps endpoint, add data-api-id with the
                             seeded component id to each option, e.g. <option data-api-id="3">. --}}
                        <select id="demo-cpu" data-fps-cpu class="pctg-select">
                            <option>Ryzen 5 7600</option>
                            <option selected>Ryzen 7 9700X</option>
                            <option>Ryzen 7 9800X3D</option>
                        </select>
                    </div>

                    <div>
                        <label for="demo-gpu" class="mb-2 block text-sm font-semibold text-slate-400">GPU</label>
                        <select id="demo-gpu" data-fps-gpu class="pctg-select">
                            <option>RTX 4060</option>
                            <option selected>RTX 5070</option>
                            <option>RTX 5080</option>
                        </select>
                    </div>

                    <div>
                        <span class="mb-2 block text-sm font-semibold text-slate-400">Resolution</span>
                        <div class="flex gap-2">
                            <button type="button" data-fps-res="1080P" class="fps-res-btn">1080P</button>
                            <button type="button" data-fps-res="1440P" class="fps-res-btn is-active">1440P</button>
                            <button type="button" data-fps-res="4K" class="fps-res-btn">4K</button>
                        </div>
                    </div>
                </div>

                {{-- Results --}}
                <div class="space-y-4 lg:col-span-2">
                    <div class="flex items-center justify-between rounded-2xl border border-slate-800 bg-[#12151c] p-5">
                        <div>
                            <div class="font-bold">Fortnite</div>
                            <div class="text-xs text-slate-500">Competitive settings</div>
                        </div>
                        <div class="text-3xl font-black text-red-500" data-fps-value="fortnite">0+</div>
                    </div>

                    <div class="flex items-center justify-between rounded-2xl border border-slate-800 bg-[#12151c] p-5">
                        <div>
                            <div class="font-bold">Warzone</div>
                            <div class="text-xs text-slate-500">Balanced settings</div>
                        </div>
                        <div class="text-3xl font-black text-red-500" data-fps-value="warzone">0+</div>
                    </div>

                    <div class="flex items-center justify-between rounded-2xl border border-slate-800 bg-[#12151c] p-5">
                        <div>
                            <div class="font-bold">Cyberpunk 2077</div>
                            <div class="text-xs text-slate-500">Ray Tracing Ultra</div>
                        </div>
                        <div class="text-3xl font-black text-red-500" data-fps-value="cyberpunk">0+</div>
                    </div>
                </div>
            </div>
        </x-pctg.glass>
    </section>

    {{-- Testimonials --}}
    <section id="testimonials" class="mb-12">
        <div class="mb-10 text-center pctg-reveal">
            <h2 class="text-4xl font-black">Players Trust PCTG</h2>
            <p class="mx-auto mt-4 max-w-3xl text-slate-400">
                Real builds, real reviews — from competitive grinders to full-time streamers.
            </p>
        </div>

        <div class="grid gap-4 pctg-reveal md:grid-cols-3" style="--reveal-delay: 120ms">
            <x-pctg.hover-card>
                <div class="flex gap-1 text-yellow-400" aria-label="5 out of 5 stars">
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                </div>
                <p class="mt-4 text-sm leading-relaxed text-slate-400">
                    The AI builder put together a 1440P rig that out-performed a system
                    twice the price from a big box store. Burn test report included,
                    zero issues in six months.
                </p>
                <div class="mt-5 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-red-500/10 font-black text-red-300 ring-1 ring-red-500/30">SK</span>
                    <div>
                        <div class="font-semibold">Sam K.</div>
                        <div class="text-xs text-slate-500">Arctic Ghost · 9800X3D</div>
                    </div>
                </div>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <div class="flex gap-1 text-yellow-400" aria-label="5 out of 5 stars">
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                </div>
                <p class="mt-4 text-sm leading-relaxed text-slate-400">
                    Streaming at 1440P with a 1080P broadcast — the FPS estimator was
                    within a couple of frames of what I actually get. Genuinely useful.
                </p>
                <div class="mt-5 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-purple-500/10 font-black text-purple-300 ring-1 ring-purple-500/30">MT</span>
                    <div>
                        <div class="font-semibold">Morgan T.</div>
                        <div class="text-xs text-slate-500">Stormbyte · Streamer</div>
                    </div>
                </div>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <div class="flex gap-1 text-yellow-400" aria-label="5 out of 5 stars">
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                    <x-pctg.icon name="star" class="h-4 w-4" />
                </div>
                <p class="mt-4 text-sm leading-relaxed text-slate-400">
                    Burn tested, cable managed, and it shipped faster than estimated.
                    The compatibility check flagged a BIOS note before I ordered —
                    that's the kind of detail that earns a repeat customer.
                </p>
                <div class="mt-5 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-yellow-500/10 font-black text-yellow-300 ring-1 ring-yellow-500/30">AR</span>
                    <div>
                        <div class="font-semibold">Ash R.</div>
                        <div class="text-xs text-slate-500">Frostbyte XT · 1440P</div>
                    </div>
                </div>
            </x-pctg.hover-card>
        </div>
    </section>

    {{-- Finance banner --}}
    <section class="mb-12">
        <div class="relative overflow-hidden rounded-3xl border border-red-500/20 bg-gradient-to-br from-red-900/20 via-[#171a21] to-black p-8 pctg-reveal md:p-10">
            <div class="absolute right-0 top-0 h-64 w-64 rounded-full bg-red-600/10 blur-[100px]" aria-hidden="true"></div>
            <div class="relative flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
                <div>
                    <h2 class="text-3xl font-black">Build now. Pay over time.</h2>
                    <p class="mt-2 text-slate-400">Spread the cost on quality systems from £59.97/month.</p>
                </div>
                <x-pctg.button href="/builder" variant="primary">
                    <x-pctg.icon name="credit-card" class="h-5 w-5" /> Explore finance options
                </x-pctg.button>
            </div>
        </div>
    </section>

    {{-- PC build guides (SEO cluster root) --}}
    <section class="mb-12 mt-12">
        <div class="mb-6 pctg-reveal">
            <h2 class="font-display text-2xl font-bold text-white md:text-3xl">PC build guides</h2>
            <p class="mt-2 text-sm text-pctg-text-secondary">Hand-picked builds by budget, game and use case.</p>
        </div>

        <div class="grid grid-cols-2 gap-4 pctg-reveal sm:grid-cols-3 lg:grid-cols-4" style="--reveal-delay: 120ms">
            <a href="/best-gaming-pc-under-1000" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">£1000</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">Entry gaming PC</div>
            </a>
            <a href="/best-gaming-pc-under-1500" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">£1500</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">1440P gaming PC</div>
            </a>
            <a href="/best-gaming-pc-under-2000" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">£2000</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">High-end gaming PC</div>
            </a>
            <a href="/best-gaming-pc-under-2500" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">£2500</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">4K gaming PC</div>
            </a>
            <a href="/best-gaming-pc-under-3000" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">£3000</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">Enthusiast gaming PC</div>
            </a>
            <a href="/best-pc-for-fortnite" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">Fortnite</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">High-FPS builds</div>
            </a>
            <a href="/best-pc-for-warzone" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">Warzone</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">Balanced 1080P-4K</div>
            </a>
            <a href="/best-pc-for-streaming" class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10 transition hover:bg-white/10 hover:ring-pctg-primary/40">
                <div class="text-xl font-bold text-white">Streaming</div>
                <div class="mt-1 text-sm text-pctg-text-secondary">Gaming + broadcast</div>
            </a>
        </div>
    </section>

    {{-- Final CTA --}}
    <section class="mb-12">
        <x-pctg.hero class="pctg-reveal text-center">
            <h2 class="pctg-pulse text-5xl font-black md:text-6xl">
                Ready To Build?
            </h2>
            <p class="mx-auto mt-6 max-w-2xl text-lg text-slate-400">
                Get Your Gamers Edge™ — the perfect PC for your budget and games is minutes away.
            </p>
            <div class="mt-10 flex flex-wrap justify-center gap-4">
                <x-pctg.button href="/builder" variant="primary" size="lg">
                    <x-pctg.icon name="sparkles" class="h-5 w-5" /> Launch AI Builder
                </x-pctg.button>
            </div>
        </x-pctg.hero>
    </section>

</x-app-layout>
