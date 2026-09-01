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

const DEMO_BUILDS = {
    gaming: {
        entry: {
            tag: '1080P Competitive Ready',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 5 7600', price: 199 },
                { cat: 'GPU', name: 'NVIDIA RTX 4060', price: 289 },
                { cat: 'RAM', name: '32GB DDR5 5600', price: 89 },
                { cat: 'SSD', name: '1TB NVMe Gen4', price: 79 },
            ],
        },
        premium: {
            tag: '1440P Ultra Ready',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 7 9700X', price: 329 },
                { cat: 'GPU', name: 'NVIDIA RTX 5070', price: 549 },
                { cat: 'RAM', name: '32GB DDR5 6000', price: 99 },
                { cat: 'SSD', name: '2TB NVMe Gen4', price: 119 },
            ],
        },
    },
    streaming: {
        entry: {
            tag: '1080P Gaming + 1080P Stream',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 7 9700X', price: 329 },
                { cat: 'GPU', name: 'NVIDIA RTX 4060', price: 289 },
                { cat: 'RAM', name: '32GB DDR5 6000', price: 99 },
                { cat: 'SSD', name: '2TB NVMe Gen4', price: 119 },
            ],
        },
        premium: {
            tag: '1440P Gaming + 1080P Stream',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 7 9700X', price: 329 },
                { cat: 'GPU', name: 'NVIDIA RTX 5070', price: 549 },
                { cat: 'RAM', name: '64GB DDR5 6000', price: 189 },
                { cat: 'SSD', name: '2TB NVMe Gen4', price: 119 },
            ],
        },
    },
    creation: {
        entry: {
            tag: '1440P Editing + Gaming',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 7 9700X', price: 329 },
                { cat: 'GPU', name: 'NVIDIA RTX 5070', price: 549 },
                { cat: 'RAM', name: '64GB DDR5 6000', price: 189 },
                { cat: 'SSD', name: '2TB NVMe Gen4', price: 119 },
            ],
        },
        premium: {
            tag: '4K Creative Workstation',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 9 9900X', price: 449 },
                { cat: 'GPU', name: 'NVIDIA RTX 5070 Ti', price: 649 },
                { cat: 'RAM', name: '64GB DDR5 6000', price: 189 },
                { cat: 'SSD', name: '4TB NVMe Gen4', price: 219 },
            ],
        },
    },
    ai: {
        entry: {
            tag: 'Local LLM + AI Dev Ready',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 7 9700X', price: 329 },
                { cat: 'GPU', name: 'NVIDIA RTX 5070', price: 549 },
                { cat: 'RAM', name: '64GB DDR5 6000', price: 189 },
                { cat: 'SSD', name: '2TB NVMe Gen4', price: 119 },
            ],
        },
        premium: {
            tag: 'Serious AI Workstation',
            parts: [
                { cat: 'CPU', name: 'AMD Ryzen 9 9950X', price: 549 },
                { cat: 'GPU', name: 'NVIDIA RTX 5080', price: 899 },
                { cat: 'RAM', name: '96GB DDR5 6000', price: 259 },
                { cat: 'SSD', name: '4TB NVMe Gen4', price: 219 },
            ],
        },
    },
};

const USE_CASE_LABELS = {
    gaming: 'Gaming',
    streaming: 'Streaming',
    creation: 'Content Creation',
    ai: 'AI Development',
};

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

const builderResultMarkup = (useCase, tag, parts, rationale) => {
    const total = parts.reduce((sum, part) => sum + part.price, 0);

    const insight = rationale
        ? `
            <div class="mt-4 rounded-xl border border-purple-500/20 bg-purple-500/5 p-4 text-sm text-purple-200">
                <p class="font-semibold">Gemini insight</p>
                <p class="mt-1 text-purple-200/80">${rationale}</p>
            </div>`
        : '';

    return `
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="pctg-badge bg-red-500/10 text-red-300">${USE_CASE_LABELS[useCase]}</span>
            <span class="text-sm text-slate-400">${tag}</span>
        </div>
        <div class="mt-4 divide-y divide-slate-800/60">
            ${parts.map(builderPartRow).join('')}
        </div>
        <div class="mt-4 flex items-center justify-between border-t border-slate-800 pt-4">
            <span class="font-bold">Estimated total</span>
            <span class="text-2xl font-black text-red-500">${formatGBP(total)}</span>
        </div>
        ${insight}
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
    const generateButton = container.querySelector('[data-demo-generate]');
    const resultPanel = container.querySelector('[data-demo-result]');

    if (!budgetInput || !budgetLabel || !generateButton || !resultPanel || caseButtons.length === 0) return;

    let useCase = 'gaming';
    let budget = Number(budgetInput.value) || 1500;

    const tierFor = (useCase, value) => (value >= 1200 ? 'premium' : 'entry');

    const renderStatic = () => {
        const build = DEMO_BUILDS[useCase][tierFor(useCase, budget)];
        resultPanel.innerHTML = builderResultMarkup(useCase, build.tag, build.parts);
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
        resultPanel.innerHTML = builderLoadingMarkup();
        generateButton.disabled = true;

        const fallback = { ...DEMO_BUILDS[useCase][tierFor(useCase, budget)], rationale: null };
        const result = (await generateFromApi()) || fallback;

        resultPanel.innerHTML = builderResultMarkup(useCase, result.tag, result.parts, result.rationale);
        generateButton.disabled = false;
    };

    caseButtons.forEach((button) => {
        button.addEventListener('click', () => {
            caseButtons.forEach((other) => other.classList.remove('is-active'));
            button.classList.add('is-active');
            useCase = button.dataset.demoCase;
        });
    });

    budgetInput.addEventListener('input', () => {
        budget = Number(budgetInput.value) || 0;
        budgetLabel.textContent = formatGBP(budget);
    });

    generateButton.addEventListener('click', generate);

    renderStatic();
});

/* ---------------------------------------------------------------- */
/* FPS estimator demo                                                */
/* ---------------------------------------------------------------- */

const FPS_MATRIX = {
    'Ryzen 5 7600|RTX 4060|1080P': { fortnite: 180, warzone: 110, cyberpunk: 75 },
    'Ryzen 5 7600|RTX 4060|1440P': { fortnite: 130, warzone: 85, cyberpunk: 55 },
    'Ryzen 5 7600|RTX 4060|4K': { fortnite: 85, warzone: 55, cyberpunk: 35 },
    'Ryzen 5 7600|RTX 5070|1080P': { fortnite: 210, warzone: 145, cyberpunk: 100 },
    'Ryzen 5 7600|RTX 5070|1440P': { fortnite: 165, warzone: 120, cyberpunk: 75 },
    'Ryzen 5 7600|RTX 5070|4K': { fortnite: 100, warzone: 70, cyberpunk: 48 },
    'Ryzen 7 9700X|RTX 4060|1080P': { fortnite: 190, warzone: 115, cyberpunk: 80 },
    'Ryzen 7 9700X|RTX 4060|1440P': { fortnite: 140, warzone: 90, cyberpunk: 58 },
    'Ryzen 7 9700X|RTX 4060|4K': { fortnite: 90, warzone: 58, cyberpunk: 38 },
    'Ryzen 7 9700X|RTX 5070|1080P': { fortnite: 240, warzone: 165, cyberpunk: 120 },
    'Ryzen 7 9700X|RTX 5070|1440P': { fortnite: 190, warzone: 145, cyberpunk: 95 },
    'Ryzen 7 9700X|RTX 5070|4K': { fortnite: 120, warzone: 85, cyberpunk: 58 },
    'Ryzen 7 9700X|RTX 5080|1080P': { fortnite: 300, warzone: 215, cyberpunk: 160 },
    'Ryzen 7 9700X|RTX 5080|1440P': { fortnite: 245, warzone: 185, cyberpunk: 128 },
    'Ryzen 7 9700X|RTX 5080|4K': { fortnite: 165, warzone: 120, cyberpunk: 90 },
    'Ryzen 7 9800X3D|RTX 4060|1080P': { fortnite: 230, warzone: 145, cyberpunk: 82 },
    'Ryzen 7 9800X3D|RTX 4060|1440P': { fortnite: 175, warzone: 115, cyberpunk: 60 },
    'Ryzen 7 9800X3D|RTX 4060|4K': { fortnite: 115, warzone: 72, cyberpunk: 40 },
    'Ryzen 7 9800X3D|RTX 5070|1080P': { fortnite: 300, warzone: 200, cyberpunk: 125 },
    'Ryzen 7 9800X3D|RTX 5070|1440P': { fortnite: 240, warzone: 175, cyberpunk: 100 },
    'Ryzen 7 9800X3D|RTX 5070|4K': { fortnite: 160, warzone: 115, cyberpunk: 62 },
    'Ryzen 7 9800X3D|RTX 5080|1080P': { fortnite: 380, warzone: 260, cyberpunk: 195 },
    'Ryzen 7 9800X3D|RTX 5080|1440P': { fortnite: 320, warzone: 225, cyberpunk: 158 },
    'Ryzen 7 9800X3D|RTX 5080|4K': { fortnite: 235, warzone: 165, cyberpunk: 125 },
    'default|1080P': { fortnite: 200, warzone: 130, cyberpunk: 90 },
    'default|1440P': { fortnite: 160, warzone: 110, cyberpunk: 75 },
    'default|4K': { fortnite: 100, warzone: 75, cyberpunk: 50 },
};

const normalizeGame = (value) => String(value).toLowerCase().replace(/[^a-z0-9]/g, '');

const fpsFromRows = (rows) => {
    const map = {};

    rows.forEach((row) => {
        const game = normalizeGame(row && row.game);

        if (!game) return;

        const fps = Number(row.fps);

        if (game.includes('fortnite')) map.fortnite = fps;
        if (game.includes('warzone')) map.warzone = fps;
        if (game.includes('cyberpunk')) map.cyberpunk = fps;
    });

    return map;
};

waitForDom(() => {
    const container = document.querySelector('[data-fps-demo]');

    if (!container) return;

    const cpuSelect = container.querySelector('[data-fps-cpu]');
    const gpuSelect = container.querySelector('[data-fps-gpu]');
    const resolutionButtons = Array.from(container.querySelectorAll('[data-fps-res]'));
    const valueNodes = {
        fortnite: container.querySelector('[data-fps-value="fortnite"]'),
        warzone: container.querySelector('[data-fps-value="warzone"]'),
        cyberpunk: container.querySelector('[data-fps-value="cyberpunk"]'),
    };

    if (!cpuSelect || !gpuSelect || resolutionButtons.length === 0) return;
    if (!valueNodes.fortnite || !valueNodes.warzone || !valueNodes.cyberpunk) return;

    let resolution = '1440P';

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

    const renderStatic = () => {
        const key = `${cpuSelect.value}|${gpuSelect.value}|${resolution}`;
        const row = FPS_MATRIX[key] || FPS_MATRIX[`default|${resolution}`];

        setFps('fortnite', row.fortnite);
        setFps('warzone', row.warzone);
        setFps('cyberpunk', row.cyberpunk);
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

            const map = fpsFromRows(rows);

            Object.keys(map).forEach((game) => setFps(game, map[game]));
        } catch (error) {
            /* keep the static estimates */
        }
    };

    const refresh = () => {
        renderStatic();
        refreshFromApi();
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
});
