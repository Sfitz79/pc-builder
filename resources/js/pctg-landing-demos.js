/* Landing-page demos: AI Builder showcase + FPS estimator.
 *
 * Both demos try the real backend endpoints first and fall back to bundled
 * demo data, so the page is fully interactive before the database is seeded:
 *   - POST /builder/ai   -> real AI build (public; anonymous visitors
 *                           get the static showcase instead).
 *   - GET  /builder/fps  -> real benchmark rows; options carry data-api-id
 *                           to opt in once catalog IDs are known.
 */

const csrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
};

/* This file is processed by Vite, NOT Blade, so `@json()` will not interpolate
 * here. Server-side config is therefore passed through a JSON island rendered
 * by welcome.blade.php and read at runtime:
 *
 *   #pctg-workload-config  { "segments": {...}, "business": {...} }
 *
 * Both fall back to empty objects so the page still works if the island is
 * missing, rather than throwing on a null dereference. */
const readJsonIsland = (id) => {
    const el = document.getElementById(id);
    if (!el) return {};
    try {
        return JSON.parse(el.textContent || '{}');
    } catch (error) {
        // Silent-fallback rule: never let a parse failure hide the config.
        console.warn('pctg-landing-demos: could not parse #' + id, error);
        return {};
    }
};

const WORKLOAD_CONFIG = readJsonIsland('pctg-workload-config');
const MODE_BLURBS = (WORKLOAD_CONFIG.segments && typeof WORKLOAD_CONFIG.segments === 'object')
    ? Object.fromEntries(Object.entries(WORKLOAD_CONFIG.segments).map(([k, v]) => [k, (v && v.blurb) || '']))
    : {};

const formatGBP = (value) => '£' + Number(value).toLocaleString('en-GB');

const waitForDom = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

/* ---------------------------------------------------------------- */
/* AI Builder showcase                                               */
/* ---------------------------------------------------------------- */
/*
 * WHY THESE NUMBERS ARE WHAT THEY ARE
 * ----------------------------------
 * The previous version of this file shipped a "1440P Ultra Ready" build
 * advertised at GBP 1,096 as the "Estimated total". Measured against our own
 * catalogue and pricing engine, that figure was wrong three separate ways:
 *
 *  1. It was not a parts total at all. It added up cpu + gpu + ram + ssd and
 *     silently omitted the motherboard, case, PSU and cooler, every one of
 *     which a machine cannot be built without.
 *  2. It was not a sellable price. Even taken at face value, pushing a GBP 1,096
 *     parts sum through BuildPricingService::completePrice() yields GBP 1,696.91
 *     all-in - delivery, build time, testing and margin.
 *  3. The individual parts were not real prices. RAM was shown at GBP 99 when
 *     the cheapest sellable 32GB DDR5-6000 kit in our catalogue is GBP 319.99,
 *     and 2TB NVMe at GBP 119 when the cheapest in-stock unit is GBP 209.00.
 *     Both were fabricated below cost purely to make the headline land.
 *
 * It also undercut our own published floors: workableBands() measures the live
 * 1440p floor at GBP 1,440, so the page was promising a 1440P machine for
 * GBP 344 less than we claim the cheapest one costs.
 *
 * THE RULE APPLIED BELOW
 * ----------------------
 * Every total shown here is the number the app's OWN pricing engine produces for
 * that build - parts plus service plus delivery plus margin, VAT-inclusive, which
 * is what config/pricing.php and the "prices include VAT" statement in llms.txt
 * describe. No total is hand-written. If a build cannot be assembled from real
 * priced parts, it is not listed.
 *
 * These are examples, and they are labelled as examples. The live quote the
 * customer gets always comes from /builder/ai, never from this file.
 */

const DEMO_BUILDS = {
    gaming: {
        entry: {
            tag: '1080P Ultra Ready',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 5 7600', price: 164.99 },
                { cat: 'GPU', name: 'Acer Nitro OC Arc B570 10GB', price: 219.00 },
                { cat: 'RAM', name: 'ADATA XPG SPECTRIX D35G RGB 32 GB DDR5-6000', price: 205.99 },
                { cat: 'SSD', name: 'Patriot P210 512GB', price: 61.99 },
                { cat: 'Board', name: 'Gigabyte A520M K V2', price: 42.99 },
                { cat: 'PSU', name: 'Gigabyte P850GM', price: 58.99 },
                { cat: 'Case', name: 'Montech AIR 100 ARGB', price: 50.04 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 1297.92,
        },
        premium: {
            tag: '1440P Ultra Ready',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 5 7600X', price: 137.99 },
                { cat: 'GPU', name: 'Asus PRIME OC GeForce RTX 5070 12GB', price: 555.00 },
                { cat: 'RAM', name: 'V-Color Manta XSky RGB 32 GB DDR5-6000', price: 319.99 },
                { cat: 'SSD', name: 'Crucial P310 w/ Heatsink 2048GB', price: 219.95 },
                { cat: 'Board', name: 'ASRock A620AM-HVS', price: 67.50 },
                { cat: 'PSU', name: 'Gigabyte P850GM', price: 58.99 },
                { cat: 'Case', name: 'NZXT H6 Flow RGB', price: 95.47 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 2108.13,
        },
    },
    streaming: {
        entry: {
            tag: '1440P Gaming + 1080P Stream',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 5 7600X', price: 137.99 },
                { cat: 'GPU', name: 'Asus DUAL OC GeForce RTX 5060 Ti 8GB', price: 317.99 },
                { cat: 'RAM', name: 'V-Color Manta XSky RGB 32 GB DDR5-6000', price: 319.99 },
                { cat: 'SSD', name: 'Kingston NV3 1024GB', price: 128.94 },
                { cat: 'Board', name: 'ASRock A620AM-HVS', price: 67.50 },
                { cat: 'PSU', name: 'Gigabyte P850GM', price: 58.99 },
                { cat: 'Case', name: 'NZXT H6 Flow RGB', price: 95.47 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 2014.31,
        },
        premium: {
            tag: '1440P Streamer',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 7 9700X', price: 241.47 },
                { cat: 'GPU', name: 'Asus PRIME OC GeForce RTX 5070 12GB', price: 555.00 },
                { cat: 'RAM', name: 'V-Color Manta XSky RGB 32 GB DDR5-6000', price: 319.99 },
                { cat: 'SSD', name: 'Crucial P310 w/ Heatsink 2048GB', price: 219.95 },
                { cat: 'Board', name: 'Gigabyte B650 EAGLE AX', price: 112.99 },
                { cat: 'PSU', name: 'MSI MAG A850GL PCIE5', price: 88.00 },
                { cat: 'Case', name: 'NZXT H9 Flow (2025) ATX Mid Tower', price: 99.98 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 2234.06,
        },
    },
    creation: {
        entry: {
            tag: '1440P Editing + Gaming',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 9 9900X', price: 296.86 },
                { cat: 'GPU', name: 'Asus PRIME OC GeForce RTX 5070 12GB', price: 555.00 },
                { cat: 'RAM', name: 'V-Color Manta XSky RGB 32 GB DDR5-6000', price: 319.99 },
                { cat: 'SSD', name: 'Crucial P310 w/ Heatsink 2048GB', price: 219.95 },
                { cat: 'Board', name: 'Gigabyte B650 EAGLE AX', price: 112.99 },
                { cat: 'PSU', name: 'MSI MAG A850GL PCIE5', price: 88.00 },
                { cat: 'Case', name: 'NZXT H9 Flow (2025) ATX Mid Tower', price: 99.98 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 2229.61,
        },
        premium: {
            tag: '4K Creative Workstation',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 9 9950X', price: 439.78 },
                { cat: 'GPU', name: 'Gigabyte WINDFORCE OC SFF RTX 5070 Ti 16GB', price: 787.74 },
                { cat: 'RAM', name: 'Crucial Pro 64 GB DDR5-6000', price: 662.58 },
                { cat: 'SSD', name: 'TEAMGROUP QX 4TB', price: 329.99 },
                { cat: 'Board', name: 'ASRock X870 Steel Legend WiFi', price: 164.39 },
                { cat: 'PSU', name: 'MSI MAG A850GL PCIE5', price: 88.00 },
                { cat: 'Case', name: 'NZXT H9 Flow (2025) ATX Mid Tower', price: 99.98 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 3161.55,
        },
    },
    ai: {
        entry: {
            tag: 'Local LLM + AI Dev',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 9 9900X', price: 296.86 },
                { cat: 'GPU', name: 'Gigabyte WINDFORCE OC SFF RTX 5070 Ti 16GB', price: 787.74 },
                { cat: 'RAM', name: 'Crucial Pro 64 GB DDR5-6000', price: 662.58 },
                { cat: 'SSD', name: 'Crucial P310 w/ Heatsink 2048GB', price: 219.95 },
                { cat: 'Board', name: 'ASRock X870 Steel Legend WiFi', price: 164.39 },
                { cat: 'PSU', name: 'MSI MAG A850GL PCIE5', price: 88.00 },
                { cat: 'Case', name: 'NZXT H9 Flow (2025) ATX Mid Tower', price: 99.98 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 2847.87,
        },
        premium: {
            tag: 'Serious AI Workstation',
            note: 'Example build. Your quote is priced live when you generate one.',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 9 9950X', price: 439.78 },
                { cat: 'GPU', name: 'PNY OC GeForce RTX 5080 16GB', price: 1159.99 },
                { cat: 'RAM', name: 'Crucial Pro 64 GB DDR5-6000', price: 662.58 },
                { cat: 'SSD', name: 'TEAMGROUP QX 4TB', price: 329.99 },
                { cat: 'Board', name: 'ASRock X870 Steel Legend WiFi', price: 164.39 },
                { cat: 'PSU', name: 'MSI MAG A850GL PCIE5', price: 88.00 },
                { cat: 'Case', name: 'NZXT H9 Flow (2025) ATX Mid Tower', price: 99.98 },
                { cat: 'Cooler', name: 'ID-COOLING FROSTFLOW X', price: 40.00 },
            ],
            allIn: 3533.91,
        },
    },
};

const USE_CASE_LABELS = {
    gaming: 'Gaming',
    streaming: 'Streaming',
    creation: 'Content Creation',
    'ai': 'AI Development',
    studio: 'Creative Studio',
    rendering: 'Pro Rendering',
    enterprise: 'Enterprise Servers',
    server: 'Servers',
    nas: 'NAS & Storage',
    'home-business': 'Home Business',
};

/* Business workload copy, read from the config island so the landing page and
 * the recommendation engine cannot disagree about what a NAS build is
 * actually constrained by. */
const WORKLOAD_META = (WORKLOAD_CONFIG.business && typeof WORKLOAD_CONFIG.business === 'object')
    ? WORKLOAD_CONFIG.business
    : {};

const INIT_BUILD_PARTS = ['cpu', 'gpu', 'ram', 'storage'];

const demoCaseButtonClasses = (active) => [
    'demo-case-btn',
    active ? 'is-active' : '',
].join(' ');

const builderPartRow = (part) => `
    <div class="flex items-center justify-between gap-4 py-3">
        <span class="w-24 text-xs font-bold uppercase tracking-wider text-slate-500">${part.cat}</span>
        <span class="flex-1 text-right font-semibold text-white">${part.name}</span>
        <span class="w-20 text-right text-sm text-slate-400">${formatGBP(part.price)}</span>
    </div>
`;

const builderResultMarkup = (useCase, tag, parts, rationale, allIn, note) => {
    /*
     * allIn is the price the app's own engine produces: parts + service +
     * delivery + margin, VAT-inclusive. It is passed in, NOT recomputed here.
     *
     * Summing the part prices instead is what produced the old, wrong headline.
     * The part list deliberately omits nothing a machine needs, so the sum is
     * now a genuine parts cost - but a parts cost is still not a sell price,
     * and showing one as "Estimated total" would repeat the original defect.
     *
     * When the live /builder/ai response supplies its own total we use that,
     * because it is authoritative. Only when there is no live total do we fall
     * back to the measured all-in figure on the demo build.
     */
    const partsSum = parts.reduce((sum, part) => sum + (Number(part.price) || 0), 0);
    const hasAllIn = Number.isFinite(Number(allIn)) && Number(allIn) > 0;

    const totalBlock = hasAllIn
        ? `
            <div class="mt-4 flex items-baseline justify-between border-t border-slate-800 pt-4">
                <div>
                    <span class="font-bold">Estimated total</span>
                    <p class="mt-1 text-xs text-slate-500">
                        Parts, build, testing, delivery and warranty. VAT included.
                    </p>
                </div>
                <span class="text-2xl font-black text-red-500">${formatGBP(allIn)}</span>
            </div>
            <p class="mt-2 text-xs text-slate-600">
                Parts cost ${formatGBP(partsSum)} &middot; the rest is what it takes to
                build it, test it, deliver it and stand behind it for two years.
            </p>`
        : '';

    const insight = rationale
        ? `
            <div class="mt-4 rounded-xl border border-purple-500/20 bg-purple-500/5 p-4 text-sm text-purple-200">
                <p class="font-semibold">Gemini insight</p>
                <p class="mt-1 text-purple-200/80">${rationale}</p>
            </div>`
        : '';

    const disclaimer = note
        ? `<p class="mt-3 text-xs text-slate-500">${note}</p>`
        : '';

    return `
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="pctg-badge bg-red-500/10 text-red-300">${USE_CASE_LABELS[useCase]}</span>
            <span class="text-sm text-slate-400">${tag}</span>
        </div>
        <div class="mt-4 divide-y divide-slate-800/60">
            ${parts.map(builderPartRow).join('')}
        </div>
        ${totalBlock}
        ${insight}
        ${disclaimer}
        <p class="mt-4 text-xs text-slate-500">Live recommendations, compatibility and FPS estimates inside the
        <a href="/builder" class="text-red-400 hover:underline">AI Builder</a>.</p>
    `;
};

const builderLoadingMarkup = () => `
    <div class="flex flex-col items-center justify-center gap-4 py-12 text-center">
        <div class="h-10 w-10 animate-spin rounded-full border-2 border-slate-700 border-t-red-500"></div>
        <p class="text-sm font-medium text-slate-400">PCTG AI is picking components…</p>
    </div>
`;

waitForDom(() => {
    const container = document.querySelector('[data-builder-demo]');

    if (!container) return;

    const caseButtons = Array.from(container.querySelectorAll('[data-demo-case]'));
    const budgetInput = container.querySelector('[data-demo-budget]');
    const budgetLabel = container.querySelector('[data-demo-budget-label]');
    const budgetFloorLabel = container.querySelector('[data-demo-budget-floor]');
    const budgetCeilingLabel = container.querySelector('[data-demo-budget-ceiling]');
    const budgetNotice = container.querySelector('[data-demo-budget-notice]');
    const generateButton = container.querySelector('[data-demo-generate]');
    const resultPanel = container.querySelector('[data-demo-result]');

    if (!budgetInput || !budgetLabel || !generateButton || !resultPanel || caseButtons.length === 0) return;

    let useCase = 'gaming';
    let budget = Number(budgetInput.value) || 1500;

    // These are FALLBACKS only, until /builder/bands answers with the live
    // measured figures. workableBands() measures and rounds the 1080p entry up
    // to the published floor (GBP 1,040 under the current pricing model), so
    // the fallback matches it rather than a stale hand-typed number.
    let floor = 1040;
    let ceiling = 3500;

    const WHATSAPP = '+447933101083';

    const money = (value) => formatGBP(value);

    // Boss directive 2026-09-28: the slider must only offer values that get a
    // build, and a typed value below the honest minimum moves itself up with a
    // plain-English explanation plus the part-new/part-used option.
    const applyFloor = (quiet) => {
        if (Number.isFinite(budget) && budget >= floor) {
            if (budgetNotice) {
                budgetNotice.hidden = true;
                budgetNotice.textContent = '';
            }

            return budget;
        }

        budget = floor;
        budgetInput.value = String(floor);
        budgetLabel.textContent = money(floor);

        if (quiet || !budgetNotice) return floor;

        budgetNotice.hidden = false;
        // Two-year warranty is stated because it is a real, documented commitment
    // (see the "What Every Build Comes With" section on the landing page).
    // It must stay true: if the warranty changes, change both places.
    budgetNotice.innerHTML = ''
            + '<p class="font-semibold text-amber-200">We have moved your budget to '
            + money(floor) + ', which is the least a brand-new 1080p machine costs when it comes to us '
            + 'fully built, tested and covered by our two-year warranty.</p>'
            + '<p class="mt-1">Below that we would have to leave something out - the memory, the proper '
            + 'cooling, or the graphics card - and we would rather be straight with you than hand you a PC '
            + 'we would not put our name on.</p>'
            + '<p class="mt-2 font-semibold text-white">Want to spend less?</p>'
            + '<p class="mt-1 text-slate-300">We can mix brand-new parts with parts we have already checked, '
            + 'tested and graded, which brings the price down while keeping the warranty on the whole machine. '
            + 'It is not something we put on the website, so give us a ring and we will price one up for you.</p>'
            + '<a class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-500 px-3 py-1.5 text-sm '
            + 'font-bold text-white hover:bg-emerald-400" href="https://wa.me/447933101083" '
            + 'target="_blank" rel="noopener"><span aria-hidden="true">💬</span> Message us on WhatsApp</a>';

        return floor;
    };

    const loadBands = async () => {
        try {
            const response = await fetch('/builder/bands', { headers: { Accept: 'application/json' } });

            if (!response.ok) return;

            const data = await response.json();
            const band = data && data.bands ? data.bands['1080p'] : null;

            if (!band) return;

            // The floor is the cheapest band; the ceiling is the TOP band's
            // ceiling, not the 1080p band's - the slider spans the whole range,
            // so capping it at the 1080p ceiling would hide every dearer build.
            const topBand = data && data.bands ? data.bands['4k'] : null;

            floor = Math.ceil((Number(band.min) || 1040) / 10) * 10;
            ceiling = Number(topBand && topBand.max) || 3500;

            budgetInput.min = String(floor);
            budgetInput.max = String(Math.max(ceiling, floor));

            if (budgetFloorLabel) budgetFloorLabel.textContent = money(floor);
            if (budgetCeilingLabel) budgetCeilingLabel.textContent = money(budgetInput.max);

            if (Number(budgetInput.value) < floor) {
                budgetInput.value = String(floor);
                budget = floor;
                budgetLabel.textContent = money(floor);
            }
        } catch (error) {
            // Keep the published fallback. A failed fetch must never be the
            // reason a customer is shown a slider that cannot build.
        }
    };

    const tierFor = (useCase, value) => (value >= 1200 ? 'premium' : 'entry');

    /* A workload the landing demo has NO measured example for.
     *
     * DEMO_BUILDS only covers the gaming workloads, because those are the only
     * ones priced from the live catalogue when the demo was written. There is
     * deliberately no fabricated server or NAS example here: inventing a parts
     * list for a workload we have not measured is exactly the GBP 1,096 failure
     * this file was rewritten to remove.
     *
     * So a business workload shows what we DO know - what the workload is for
     * and what actually constrains the hardware - and hands off to the live
     * builder, which prices from the real catalogue. Honest and useful beats a
     * confident invented number. */
    const renderNoMeasuredExample = (workload) => {
        const w = workload || {};
        resultPanel.innerHTML = `
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="pctg-badge bg-blue-500/10 text-blue-300">Business &amp; Pro</span>
                <span class="text-sm text-slate-400">${w.label || 'Professional system'}</span>
            </div>
            <p class="mt-4 text-sm leading-relaxed text-slate-400">${w.blurb || ''}</p>
            ${w.guidance ? `<p class="mt-3 text-sm leading-relaxed text-slate-300">${w.guidance}</p>` : ''}
            <p class="mt-4 text-sm leading-relaxed text-slate-400">
                We do not publish an example price for this workload, because we have not
                measured one. Ask the builder for a live quote from the current catalogue and
                it will price the real thing.
            </p>
            <div class="mt-6">
                <a href="/builder" class="inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-5 py-2.5 font-bold text-white transition hover:bg-blue-500">
                    Get a live quote &rarr;
                </a>
            </div>
        `;
    };

    const renderStatic = () => {
        const group = DEMO_BUILDS[useCase];
        if (!group) {
            renderNoMeasuredExample(WORKLOAD_META[useCase]);
            return;
        }
        const build = group[tierFor(useCase, budget)];
        resultPanel.innerHTML = builderResultMarkup(useCase, build.tag, build.parts, null, build.allIn, build.note);
    };

    const partsFromApi = (payload) => {
        const components = (payload && typeof payload === 'object' && payload.components) || {};

        return INIT_BUILD_PARTS
            .map((slug) => {
                const item = components[slug];

                if (!item || typeof item !== 'object') return null;

                return {
                    cat: slug.toUpperCase(),
                    name: item.name,
                    price: Number(item.price) || 0,
                };
            })
            .filter(Boolean);
    };

    const generateFromApi = async () => {
        try {
            const response = await fetch('/builder/ai', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ budget, purpose: useCase, resolution: '1440P' }),
            });

            if (!response.ok) return null;

            const payload = await response.json();
            const parts = partsFromApi(payload);

            if (parts.length === 0) return null;

            return {
                tag: 'Tuned to your budget',
                parts,
                rationale: payload.ai && payload.ai.rationale ? payload.ai.rationale : null,
            };
        } catch (error) {
            return null;
        }
    };

    const generate = async () => {
        budget = applyFloor(false);

        resultPanel.innerHTML = builderLoadingMarkup();
        generateButton.disabled = true;

        const demo = DEMO_BUILDS[useCase] ? DEMO_BUILDS[useCase][tierFor(useCase, budget)] : null;
        const result = (await generateFromApi())
            || (demo ? { ...demo, rationale: null } : null);

        // No live quote AND no measured example for this workload: say so and
        // hand off, rather than substituting a gaming build or a made-up price.
        if (!result) {
            renderNoMeasuredExample(WORKLOAD_META[useCase]);
            generateButton.disabled = false;
            return;
        }

        resultPanel.innerHTML = builderResultMarkup(
            useCase,
            result.tag,
            result.parts,
            result.rationale,
            result.allIn,
            result.note
        );
        generateButton.disabled = false;
    };

    caseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            caseButtons.forEach((other) => other.classList.remove('is-active'));
            button.classList.add('is-active');
            useCase = button.dataset.demoCase;
            renderStatic();
        });
    });

    /* ---- MODE SWITCH (gaming <-> business) -------------------------------
     * The workload buttons carry data-demo-segment, so the mode switch filters
     * them without hardcoding any slug. On switching to a mode that has no
     * measured demo example, the panel shows the hand-off panel instead of a
     * gaming build - the UI must never claim to be a business configurator
     * while displaying gaming parts. */
    const modeButtons = Array.from(container.querySelectorAll('[data-demo-mode]'));
    const modeNote = container.querySelector('[data-demo-mode-note]');

    const applyMode = (mode) => {
        caseButtons.forEach((btn) => {
            const seg = btn.dataset.demoSegment || 'gaming';
            btn.hidden = seg !== mode;
            if (seg !== mode) btn.classList.remove('is-active');
        });

        const active = caseButtons.find((b) => b.classList.contains('is-active'));
        if (!active || (active.dataset.demoSegment || 'gaming') !== mode) {
            const first = caseButtons.find((b) => (b.dataset.demoSegment || 'gaming') === mode);
            if (first) {
                first.classList.add('is-active');
                useCase = first.dataset.demoCase;
            }
        }

        if (modeNote) modeNote.textContent = MODE_BLURBS[mode] || '';
        renderStatic();
    };

    modeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            modeButtons.forEach((other) => {
                other.classList.remove('is-active');
                other.setAttribute('aria-pressed', 'false');
            });
            button.classList.add('is-active');
            button.setAttribute('aria-pressed', 'true');
            applyMode(button.dataset.demoMode);
        });
    });

    budgetInput.addEventListener('input', () => {
        budget = Number(budgetInput.value) || 0;
        budgetLabel.textContent = money(budget);
    });

    budgetInput.addEventListener('change', () => {
        budget = applyFloor(false);
    });

    generateButton.addEventListener('click', generate);

    loadBands();

    renderStatic();
});

/* ---------------------------------------------------------------- */
/* FPS estimator demo                                                */
/* ---------------------------------------------------------------- */

const FPS_MATRIX = {
    'Ryzen 5 7600|RTX 4060|1080P': { fortnite: 180, warzone: 110, cyberpunk: 75, starfield: 60, baldursgate3: 95, marvelrivals: 145 },
    'Ryzen 5 7600|RTX 4060|1440P': { fortnite: 130, warzone: 85, cyberpunk: 55, starfield: 45, baldursgate3: 70, marvelrivals: 105 },
    'Ryzen 5 7600|RTX 4060|4K': { fortnite: 85, warzone: 55, cyberpunk: 35, starfield: 28, baldursgate3: 42, marvelrivals: 65 },
    'Ryzen 5 7600|RTX 5070|1080P': { fortnite: 210, warzone: 145, cyberpunk: 100, starfield: 78, baldursgate3: 128, marvelrivals: 178 },
    'Ryzen 5 7600|RTX 5070|1440P': { fortnite: 165, warzone: 120, cyberpunk: 75, starfield: 60, baldursgate3: 98, marvelrivals: 138 },
    'Ryzen 5 7600|RTX 5070|4K': { fortnite: 100, warzone: 70, cyberpunk: 48, starfield: 38, baldursgate3: 60, marvelrivals: 82 },
    'Ryzen 7 9700X|RTX 4060|1080P': { fortnite: 190, warzone: 115, cyberpunk: 80, starfield: 63, baldursgate3: 100, marvelrivals: 152 },
    'Ryzen 7 9700X|RTX 4060|1440P': { fortnite: 140, warzone: 90, cyberpunk: 58, starfield: 47, baldursgate3: 72, marvelrivals: 110 },
    'Ryzen 7 9700X|RTX 4060|4K': { fortnite: 90, warzone: 58, cyberpunk: 38, starfield: 30, baldursgate3: 44, marvelrivals: 66 },
    'Ryzen 7 9700X|RTX 5070|1080P': { fortnite: 240, warzone: 165, cyberpunk: 120, starfield: 88, baldursgate3: 148, marvelrivals: 205 },
    'Ryzen 7 9700X|RTX 5070|1440P': { fortnite: 190, warzone: 145, cyberpunk: 95, starfield: 72, baldursgate3: 115, marvelrivals: 168 },
    'Ryzen 7 9700X|RTX 5070|4K': { fortnite: 120, warzone: 85, cyberpunk: 58, starfield: 46, baldursgate3: 72, marvelrivals: 100 },
    'Ryzen 7 9700X|RTX 5080|1080P': { fortnite: 300, warzone: 215, cyberpunk: 160, starfield: 115, baldursgate3: 195, marvelrivals: 280 },
    'Ryzen 7 9700X|RTX 5080|1440P': { fortnite: 245, warzone: 185, cyberpunk: 128, starfield: 95, baldursgate3: 158, marvelrivals: 225 },
    'Ryzen 7 9700X|RTX 5080|4K': { fortnite: 165, warzone: 120, cyberpunk: 90, starfield: 68, baldursgate3: 108, marvelrivals: 155 },
    'Ryzen 7 9800X3D|RTX 4060|1080P': { fortnite: 230, warzone: 145, cyberpunk: 82, starfield: 70, baldursgate3: 105, marvelrivals: 158 },
    'Ryzen 7 9800X3D|RTX 4060|1440P': { fortnite: 175, warzone: 115, cyberpunk: 60, starfield: 50, baldursgate3: 78, marvelrivals: 118 },
    'Ryzen 7 9800X3D|RTX 4060|4K': { fortnite: 115, warzone: 72, cyberpunk: 40, starfield: 33, baldursgate3: 48, marvelrivals: 70 },
    'Ryzen 7 9800X3D|RTX 5070|1080P': { fortnite: 300, warzone: 200, cyberpunk: 125, starfield: 95, baldursgate3: 160, marvelrivals: 222 },
    'Ryzen 7 9800X3D|RTX 5070|1440P': { fortnite: 240, warzone: 175, cyberpunk: 100, starfield: 78, baldursgate3: 128, marvelrivals: 178 },
    'Ryzen 7 9800X3D|RTX 5070|4K': { fortnite: 160, warzone: 115, cyberpunk: 62, starfield: 50, baldursgate3: 80, marvelrivals: 110 },
    'Ryzen 7 9800X3D|RTX 5080|1080P': { fortnite: 380, warzone: 260, cyberpunk: 195, starfield: 145, baldursgate3: 242, marvelrivals: 335 },
    'Ryzen 7 9800X3D|RTX 5080|1440P': { fortnite: 320, warzone: 225, cyberpunk: 158, starfield: 118, baldursgate3: 185, marvelrivals: 265 },
    'Ryzen 7 9800X3D|RTX 5080|4K': { fortnite: 235, warzone: 165, cyberpunk: 125, starfield: 95, baldursgate3: 140, marvelrivals: 202 },
    'default|1080P': { fortnite: 200, warzone: 130, cyberpunk: 90, starfield: 70, baldursgate3: 115, marvelrivals: 160 },
    'default|1440P': { fortnite: 160, warzone: 110, cyberpunk: 75, starfield: 60, baldursgate3: 90, marvelrivals: 130 },
    'default|4K': { fortnite: 100, warzone: 75, cyberpunk: 50, starfield: 40, baldursgate3: 60, marvelrivals: 85 },
};

const normalizeGame = (value) => {
    const key = String(value).toLowerCase().replace(/[^a-z0-9]/g, '');
    if (key.includes('fortnite')) return 'fortnite';
    if (key.includes('warzone')) return 'warzone';
    if (key.includes('cyberpunk')) return 'cyberpunk';
    if (key.includes('starfield')) return 'starfield';
    if (key.includes('baldurs')) return 'baldursgate3';
    if (key.includes('rivals')) return 'marvelrivals';
    return key;
};

const FPS_GAMES = ['fortnite', 'warzone', 'cyberpunk', 'starfield', 'baldursgate3', 'marvelrivals'];

const fpsFromRows = (rows) => {
    const map = {};

    rows.forEach((row) => {
        const game = normalizeGame(row && row.game);

        if (!game || !FPS_GAMES.includes(game)) return;

        map[game] = Number(row.fps);
    });

    return map;
};

waitForDom(() => {
    const container = document.querySelector('[data-fps-demo]');

    if (!container) return;

    const cpuSelect = container.querySelector('[data-fps-cpu]');
    const gpuSelect = container.querySelector('[data-fps-gpu]');
    const resolutionButtons = Array.from(container.querySelectorAll('[data-fps-res]'));
    const resultPanel = container.querySelector('[data-fps-results]');

    if (!cpuSelect || !gpuSelect || resolutionButtons.length === 0 || !resultPanel) return;

    let resolution = '1440P';

    // --- Populate the result rows for every tracked game ---
    const GAME_LABELS = {
        fortnite: ['Fortnite', 'Competitive settings'],
        warzone: ['Warzone', 'Balanced settings'],
        cyberpunk: ['Cyberpunk 2077', 'Ray Tracing Ultra'],
        starfield: ['Starfield', 'Ultra settings'],
        baldursgate3: ['Baldur\'s Gate 3', 'Ultra settings'],
        marvelrivals: ['Marvel Rivals', 'High settings'],
    };

    const valueNodes = {};

    FPS_GAMES.forEach((game) => {
        const [label, sublabel] = GAME_LABELS[game];
        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-center justify-between rounded-2xl border border-slate-800 bg-[#12151c] p-5';
        wrapper.innerHTML = `
            <div>
                <div class="font-bold">${label}</div>
                <div class="text-xs text-slate-500">${sublabel}</div>
            </div>
            <div class="text-3xl font-black text-red-500" data-fps-value="${game}">0+</div>
        `;
        resultPanel.appendChild(wrapper);
        valueNodes[game] = wrapper.querySelector('[data-fps-value]');
    });

    const pop = (node) => {
        if (!node) return;

        node.classList.remove('pctg-fps-pop');
        void node.offsetWidth;
        node.classList.add('pctg-fps-pop');
    };

    const setFps = (game, fps) => {
        const node = valueNodes[game];

        if (!node || !Number.isFinite(fps)) return;

        node.textContent = `${Math.round(fps)}+`;
        pop(node);
    };

    const resetResults = () => {
        FPS_GAMES.forEach((game) => {
            const node = valueNodes[game];
            if (node) node.textContent = '0+';
        });
    };

    const renderAll = (map) => {
        FPS_GAMES.forEach((game) => setFps(game, map ? map[game] : undefined));
    };

    const selectedChipset = (select) => {
        const option = select.selectedOptions[0];
        return option ? option.dataset.chipset || option.textContent.trim() : null;
    };

    const renderStatic = () => {
        const key = `${selectedChipset(cpuSelect)}|${selectedChipset(gpuSelect)}|${resolution}`;
        const row = FPS_MATRIX[key] || FPS_MATRIX[`default|${resolution}`];

        renderAll(row || {});
    };

    const refreshFromApi = async () => {
        const gpuId = gpuSelect.selectedOptions[0] ? gpuSelect.selectedOptions[0].dataset.apiId : '';
        const cpuId = cpuSelect.selectedOptions[0] ? cpuSelect.selectedOptions[0].dataset.apiId : '';

        if (!gpuId) return;

        try {
            const params = new URLSearchParams({ gpu_id: gpuId, resolution });
            if (cpuId) params.set('cpu_id', cpuId);

            const response = await fetch(`/builder/fps?${params.toString()}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) return;

            const rows = await response.json();

            if (!Array.isArray(rows) || rows.length === 0) return;

            renderAll(fpsFromRows(rows));
        } catch (error) {
            /* keep the static estimates */
        }
    };

    const refresh = () => {
        resetResults();
        renderStatic();
        refreshFromApi();
    };

    // --- Load chipsets from the live catalog (fall back to current options) ---
    const populateFromCatalog = async () => {
        try {
            const response = await fetch('/builder/catalog', {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) return;

            const catalog = await response.json();

            const materialize = (select, slug) => {
                const items = catalog && Array.isArray(catalog[slug]) ? catalog[slug] : [];

                if (items.length === 0) return;

                const seen = new Set();
                const chipsets = [];

                items.forEach((item) => {
                    const label = item.chipset || item.name;
                    if (seen.has(label)) return;
                    seen.add(label);
                    chipsets.push({ label, id: item.id });
                });

                if (chipsets.length === 0) return;

                const current = select.value;
                const opts = chipsets.map(({ label, id }, idx) => {
                    const opt = document.createElement('option');
                    opt.textContent = label;
                    opt.value = label;
                    opt.dataset.chipset = label;
                    opt.dataset.apiId = String(id);
                    return opt;
                });

                select.innerHTML = '';
                opts.forEach((opt) => select.appendChild(opt));
                select.value = current && seen.has(current) ? current : opts[0] ? opts[0].value : '';

                refresh();
            };

            materialize(cpuSelect, 'cpu');
            materialize(gpuSelect, 'gpu');
        } catch (error) {
            /* keep the bundled options */
        }
    };

    cpuSelect.addEventListener('change', refresh);
    gpuSelect.addEventListener('change', refresh);

    resolutionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            resolutionButtons.forEach((other) => other.classList.remove('is-active'));
            button.classList.add('is-active');
            resolution = button.dataset.fpsRes;
            refresh();
        });
    });

    refresh();
    populateFromCatalog();
});
