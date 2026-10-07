{{-- Was title="Welcome", so the live <title> was literally
     "Welcome | PCTG Builder" and every shared link read "Welcome".
     This is what search results and social previews show. --}}
<x-app-layout
    title="Custom Gaming PCs, Built to Order in the UK"
    description="Configure your own custom gaming PC to your spec. Real UK prices, compatibility checked, built and tested in Bristol, two-year warranty and free UK delivery."
>

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

            {{-- The real "The Gamers Edge" wordmark, supplied by the Boss from
                 OneDrive/PCTECHGUY/Marketing/PCTG Branding/The Gamers Edge.png.
                 Its source background was already transparent, and
                 scripts/prep-boss-assets.cs keeps it that way rather than
                 keying it. This is the actual brand tagline, not text
                 retyped into a font, so the storefront and the marketing
                 material say the same thing. --}}
            <img
                src="{{ asset('img/brand/pctg-tagline.png') }}"
                alt="The Gamers Edge"
                width="360"
                height="52"
                loading="eager"
                decoding="async"
                class="pctg-landing-reveal mt-7 h-auto w-[15rem] sm:w-[19rem]"
                style="animation-delay: 300ms"
            >

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

    {{-- These four counters used to read "500+ Systems Built", "4.9★ Customer
         Rating", "28+ Years Experience" and "99.9% Compatibility Success".

         None of those four numbers could be substantiated from any source in the
         business, and an aggregate customer rating is exactly the kind of claim
         the DMCC Act 2024 makes publishers responsible for. They have been
         replaced with statements that are true and checkable by the customer.

         Do not put a number back here unless it can be produced from a record.
         A blank is honest; an invented count is not. --}}
    <section class="mb-12">
        <div class="grid grid-cols-2 gap-4 pctg-reveal xl:grid-cols-4">
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">2yr</div>
                <div class="mt-3 text-slate-400">Warranty On Every Build</div>
            </div>
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">100%</div>
                <div class="mt-3 text-slate-400">Compatibility Checked</div>
            </div>
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">1080p&ndash;4K</div>
                <div class="mt-3 text-slate-400">Every Budget Covered</div>
            </div>
            <div class="pctg-metric text-center">
                <div class="text-5xl font-black text-red-500">UK</div>
                <div class="mt-3 text-slate-400">Built And Warrantied Here</div>
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

    {{-- Featured systems.

         THE PHOTOS ARE REAL. The Boss supplied these three machines from
         OneDrive/PCTECHGUY/Systems and the marketing library, so the builds
         genuinely exist.

         THE SPEC LISTS THAT USED TO BE HERE ARE NOT. They read "Ryzen 7
         9700X / RTX 5070 Ti / 220+ FPS Fortnite @ 1440P", "Ryzen 7 9800X3D /
         RTX 5080", "Ryzen 7 9700X / RTX 5070 (NVENC)" and none of it could be
         substantiated. Worse, the Arctic Ghost photograph visibly shows an
         RTX 2060 in the chassis while its own folder is named "artic ghost
         9060xt" - so the card would have contradicted its own image. Putting a
         spec list beside a real photo of the machine turns the photo into
         evidence for a claim, and an unverifiable claim next to evidence is
         worse than no claim.

         So the cards now carry the real photograph, the real name, and a CTA
         into the builder. No spec is stated until it can be read from the
         machine. If the Boss supplies the confirmed part list for each of the
         three, put it back here - the layout has room for it.

         NOTE ON BACKGROUNDS: Arctic Ghost is shot on white, the other two on
         black. Each card gets a matching backdrop so none of them shows a
         white rectangle on the dark page. --}}
    <section id="systems" class="mb-12">
        <div class="mb-10 text-center pctg-reveal">
            <h2 class="text-4xl font-black">Featured Systems</h2>
            <p class="mx-auto mt-4 max-w-3xl text-slate-400">
                Real machines, built and photographed in our own workshop. Tell us what you
                play and we will spec you one the same way — or start from one of these.
            </p>
        </div>

        <div class="grid gap-4 pctg-reveal lg:grid-cols-3" style="--reveal-delay: 120ms">
            @php
                $featuredSystems = [
                    [
                        'name' => 'Frostbyte XT',
                        'tag'  => 'Gaming',
                        'badge'=> 'bg-red-500/10 text-red-300',
                        'image'=> 'img/systems/frostbyte-xt.jpg',
                        'alt'  => 'Frostbyte XT — a black mid-tower custom gaming PC with blue RGB fans and a tempered glass side panel.',
                        'w'    => 1200, 'h' => 800, 'bg' => 'bg-black',
                    ],
                    [
                        'name' => 'Arctic Ghost',
                        'tag'  => 'White Build',
                        'badge'=> 'bg-slate-500/10 text-slate-300',
                        'image'=> 'img/systems/arctic-ghost.jpg',
                        'alt'  => 'Arctic Ghost — a white custom gaming PC with a full-height glass panel and RGB fans and an AIO liquid cooler.',
                        'w'    => 866, 'h' => 766, 'bg' => 'bg-white',
                    ],
                    [
                        'name' => 'Stormbyte',
                        'tag'  => 'Streamer',
                        'badge'=> 'bg-teal-500/10 text-teal-300',
                        'image'=> 'img/systems/stormbyte.jpg',
                        'alt'  => 'Stormbyte — a black custom gaming PC with teal RGB lighting and a mesh front panel.',
                        'w'    => 1024, 'h' => 1024, 'bg' => 'bg-black',
                    ],
                ];
            @endphp

            @foreach ($featuredSystems as $system)
                <div class="overflow-hidden rounded-2xl border border-white/5 bg-pctg-surface transition hover:border-red-500/30">
                    <div class="{{ $system['bg'] }} flex aspect-[4/3] items-center justify-center overflow-hidden">
                        <img
                            src="{{ asset($system['image']) }}"
                            alt="{{ $system['alt'] }}"
                            width="{{ $system['w'] }}"
                            height="{{ $system['h'] }}"
                            loading="lazy"
                            decoding="async"
                            class="h-full w-full object-contain p-3"
                        >
                    </div>
                    <div class="p-6">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-2xl font-black">{{ $system['name'] }}</h3>
                            <span class="pctg-badge {{ $system['badge'] }}">{{ $system['tag'] }}</span>
                        </div>
                        <p class="mt-3 text-sm text-slate-400">
                            Photographed in our workshop. Ask us for the full part list, or build your
                            own from scratch in the configurator.
                        </p>
                        <div class="mt-6">
                            <x-pctg.button href="/builder" variant="secondary">Build one like this</x-pctg.button>
                        </div>
                    </div>
                </div>
            @endforeach
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

                    {{-- Starts at the cheapest machine we can genuinely build
                         and deliver at 1080p, not a round number we cannot
                         honour (boss directive 2026-09-28). pctg-landing-demos.js
                         overwrites these from /builder/bands so the slider can
                         never drift from the real measured floor. --}}
                    <input
                        type="range"
                        min="1350"
                        max="3500"
                        step="10"
                        value="1500"
                        data-demo-budget
                        class="mt-4 w-full accent-red-500"
                        aria-label="Build budget"
                    >

                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-slate-400" data-demo-budget-floor>£1,350</span>
                        <span class="font-bold text-white" data-demo-budget-label>£1,500</span>
                        <span class="text-slate-400" data-demo-budget-ceiling>£3,500</span>
                    </div>

                    <div
                        data-demo-budget-notice
                        x-cloak
                        hidden
                        class="mt-3 rounded-xl border border-amber-500/50 bg-amber-500/10 p-3 text-sm text-amber-100"
                        role="status"
                        aria-live="polite"
                    ></div>

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
                        {{-- Options are populated from the live catalog, grouped by chipset. --}}
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

                {{-- Results (rows are built from the live benchmark grid by JS) --}}
                <div class="grid gap-4 lg:col-span-2" data-fps-results></div>
            </div>
        </x-pctg.glass>
    </section>

    {{-- Testimonials --}}
    {{-- What you get on every build.
         This section REPLACED a "Players Trust PCTG" testimonial block that
         carried three five-star reviews attributed to invented people
         ("Sam K.", "Morgan T.", "Ash R."), each of which praised one of three
         build names that existed nowhere but in this file.

         Publishing invented customer reviews is a breach of the Digital
         Markets, Competition and Consumers Act 2024, and it put the one thing
         that actually earns money - the reputation - at risk. The claims are
         gone rather than softened.

         Every statement below is something we can actually honour, and each
         links to the page that explains it. If a claim cannot be substantiated
         it does not belong here. Do not add testimonials back here until there
         are real, attributable ones. --}}
    <section id="included" class="mb-12">
        <div class="mb-10 text-center pctg-reveal">
            <h2 class="text-4xl font-black">What Every Build Comes With</h2>
            <p class="mx-auto mt-4 max-w-3xl text-slate-400">
                No mystery extras and no vague promises — here is exactly what is included
                on every machine, and what happens if something goes wrong.
            </p>
        </div>

        <div class="grid gap-4 pctg-reveal md:grid-cols-3" style="--reveal-delay: 120ms">
            <x-pctg.hover-card>
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-pctg-success/10 text-pctg-success ring-1 ring-pctg-success/30">
                    <x-pctg.icon name="shield-check" class="h-6 w-6" />
                </div>
                <h3 class="text-lg font-bold">Two-year warranty</h3>
                <p class="mt-3 text-sm leading-relaxed text-slate-400">
                    The whole machine is covered for two years, not just the parts that
                    arrive faulty. If it goes wrong, we sort it.
                </p>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-pctg-primary/10 text-pctg-primary-hover ring-1 ring-pctg-primary/30">
                    <x-pctg.icon name="cpu" class="h-6 w-6" />
                </div>
                <h3 class="text-lg font-bold">Checked before it ships</h3>
                <p class="mt-3 text-sm leading-relaxed text-slate-400">
                    Socket, wattage, clearance and BIOS are checked on the build you
                    actually order. If a part will not work with the rest, we change it
                    rather than ship it and let you find out.
                </p>
            </x-pctg.hover-card>

            <x-pctg.hover-card>
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-pctg-warning/10 text-pctg-warning ring-1 ring-pctg-warning/30">
                    <x-pctg.icon name="gauge" class="h-6 w-6" />
                </div>
                <h3 class="text-lg font-bold">Honest FPS estimates</h3>
                <p class="mt-3 text-sm leading-relaxed text-slate-400">
                    Frame rates are estimates from benchmark data, labelled as
                    estimates. We would rather quote a number we can stand behind
                    than a flattering one.
                </p>
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
