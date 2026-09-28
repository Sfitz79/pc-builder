window.builderState = () => ({

    selected: {
        cpu: null,
        motherboard: null,
        gpu: null,
        ram: null,
        storage: null,
        psu: null,
        case: null,
        cooler: null
    },

    componentModal: false,

    currentCategory: null,

    search: '',

    purpose: null,
    budget: 1500,
    resolution: '1440P',

        compatibility: {
        cpuMotherboard: true,
        ramSupported: true,
        powerEnough: true,
        gpuClearance: true
    },

    fpsResults: [],

    aiRecommendation: null,

    aiIdealBuild: null,

    loading: false,

    saving: false,

    missingWarning: null,

    savedUrl: null,

    savedBuilds: [],

    selectedBuildId: '',

    loadedBuild: null,

    livePrice: null,

    // --- 3D Build View -------------------------------------------------------
    viewportOpen: false,

    viewportHandle: null,

    viewportParts: [],

    rendering3d: false,

    render3dUrl: null,

    render3dError: null,

    endpoints: {
        catalog: '/builder/catalog',
        fps: '/builder/fps',
        bands: '/builder/bands',
        ai: '/builder/ai',
        validate: '/builder/validate',
        price: '/builder/price',
        builds: '/builder/builds'
    },

    // --- Honest, measured price bands (boss directive 2026-09-28) ----------
    // Every band floor is the price of a real, complete, AAA-at-high-settings
    // machine measured from the live catalogue. Until the endpoint answers we
    // use the published fallback so the slider is never briefly wrong.
    bands: {
        '1080p': { min: 1350, max: 1500, label: '1080p' },
        '1440p': { min: 1530, max: 2500, label: '1440p' },
        '4k': { min: 1960, max: 3500, label: '4K' }
    },

    bandsLoaded: false,

    // Set when a typed budget is below the honest minimum. Drives the on-screen
    // explanation and the part-new/part-used suggestion.
    budgetNotice: null,

    init() {
        this.loadCatalog();
        this.loadBuilds();
        this.loadBands();
    },

    bandKey() {
        return (this.resolution || '').toString().toUpperCase().includes('4K') ? '4k'
            : (this.resolution || '').toString().toUpperCase().includes('1440') ? '1440p'
            : '1080p';
    },

    currentBand() {
        return this.bands[this.bandKey()] || this.bands['1080p'];
    },

    // The smallest budget that will actually produce a build at this
    // resolution. The slider starts here, so every value it can show is a
    // value that gets a machine.
    budgetFloor() {
        return Math.ceil((this.currentBand().min || 0) / 10) * 10;
    },

    async loadBands() {
        try {
            const response = await fetch(this.endpoints.bands, {
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) return;

            const data = await response.json();

            if (data && data.bands) {
                this.bands = data.bands;
                this.bandsLoaded = true;
            }
        } catch (e) {
            // Keep the published fallback bands. A failed fetch must never be
            // the reason a customer is shown a slider that cannot build.
        }
    },

    /**
     * Called whenever the budget changes. If the customer has typed less than
     * the honest minimum, move the value up to that minimum and explain why in
     * plain English, offering the part-new/part-used route on WhatsApp.
     *
     * The server clamps too. This is not duplication for its own sake - it is
     * so the customer is corrected while they are looking at the field,
     * instead of discovering it after pressing the button.
     */
    applyBudgetFloor() {
        const floor = this.budgetFloor();

        if (!floor || this.budget === null || this.budget === undefined || this.budget === '') {
            this.budgetNotice = null;

            return false;
        }

        const asked = Number(this.budget);

        if (Number.isNaN(asked) || asked >= floor) {
            this.budgetNotice = null;

            return false;
        }

        this.budget = floor;
        this.budgetNotice = {
            raised: true,
            min: floor,
            label: this.currentBand().label,
            message: 'We have moved your budget to ' + this.money(floor) + ', which is the least a brand-new '
                + this.currentBand().label + ' machine costs when it comes to us fully built, tested and covered '
                + 'by our two-year warranty. Below that we would have to leave something out - the memory, the '
                + 'proper cooling, or the graphics card - and we would rather be straight with you than hand you '
                + 'a PC we would not put our name on.',
            hybrid: {
                available: true,
                headline: 'Want to spend less?',
                message: 'We can mix brand-new parts with parts we have already checked, tested and graded, '
                    + 'which brings the price down while keeping the warranty on the whole machine. It is not '
                    + 'something we put on the website, so give us a ring and we will price one up for you.',
                whatsapp: '+447933101083',
                whatsapp_url: 'https://wa.me/447933101083'
            }
        };

        return true;
    },

    money(value) {
        return '£' + Math.round(Number(value) || 0).toLocaleString('en-GB');
    },

    dismissBudgetNotice() {
        this.budgetNotice = null;
    },

    csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    },

    selectionStorageKey() {
        return 'pctg.checkout.selection';
    },

    persistSelection() {
        const selection = {
            components: Object.entries(this.selected)
                .filter(([category, item]) => item && item.id)
                .map(([category, item]) => ({
                    category,
                    id: item.id,
                    name: item.name,
                    price: item.price,
                    tags: item.tags || null
                })),
            resolution: this.resolution,
            budget: this.budget,
            purpose: this.purpose
        };

        sessionStorage.setItem(this.selectionStorageKey(), JSON.stringify(selection));
    },

    checkout() {
        if (!this.buildComplete) {
            this.showMissingWarning();
            return;
        }

        this.persistSelection();
        window.location.href = '/builder/checkout';
    },

    missingComponents() {
        return Object.entries(this.selected)
            .filter(([category, item]) => !item || !item.id)
            .map(([category]) => category);
    },

    get buildComplete() {
        return this.missingComponents().length === 0;
    },

    showMissingWarning() {
        const missing = this.missingComponents();
        this.missingWarning = missing;
    },

    async loadCatalog() {
        try {
            const response = await fetch(this.endpoints.catalog);
            if (!response.ok) return;

            const data = await response.json();
            const merged = { ...this.catalog };

            for (const category of Object.keys(data)) {
                if (Array.isArray(data[category]) && data[category].length) {
                    merged[category] = data[category];
                }
            }

            if (Object.keys(merged).length) {
                this.catalog = merged;
                // Expose the DB-dimensioned catalogue to the 3D viewport.
                window.pctgCatalog = merged;
            }
        } catch (e) {
            // Keep the bundled static catalog as a fallback.
        }
    },

    catalog: {
        cpu: [
            { name: 'AMD Ryzen 9700X', price: 329, socket: 'AM5', tags: '8 Core / 16 Thread' },
            { name: 'AMD Ryzen 7800X3D', price: 389, socket: 'AM5', tags: '8 Core / Gaming' },
            { name: 'Intel Core i5-14600K', price: 279, socket: 'LGA1700', tags: '14 Core / Hybrid' }
        ],
        motherboard: [
            { name: 'ASUS B650-A Gaming', price: 189, socket: 'AM5', tags: 'DDR5 / WiFi' },
            { name: 'MSI X870E Tomahawk', price: 349, socket: 'AM5', tags: 'DDR5 / PCIe 5.0' },
            { name: 'Gigabyte Z790 Aorus', price: 299, socket: 'LGA1700', tags: 'DDR5 / PCIe 5.0' }
        ],
        gpu: [
            { name: 'RTX 5070 Ti', price: 799, wattage: 300, tags: '16GB GDDR7 / 4K' },
            { name: 'RTX 5080', price: 1099, wattage: 360, tags: '16GB GDDR7 / DLSS 4' },
            { name: 'RTX 5090', price: 1999, wattage: 575, tags: '32GB GDDR7 / Flagship' },
            { name: 'RX 9070 XT', price: 549, wattage: 304, tags: '16GB GDDR6 / FSR 4' }
        ],
        ram: [
            { name: '32GB DDR5 6000', price: 119, tags: '2x16GB / CL30' },
            { name: '64GB DDR5 6000', price: 219, tags: '2x32GB / CL30' }
        ],
        storage: [
            { name: '1TB NVMe Gen4', price: 89, tags: '7000MB/s' },
            { name: '2TB NVMe Gen4', price: 139, tags: '7000MB/s' },
            { name: '4TB NVMe Gen4', price: 249, tags: '7000MB/s' }
        ],
        psu: [
            { name: '650W 80+ Gold', price: 99, wattage: 650, tags: 'ATX 3.1' },
            { name: '850W 80+ Gold', price: 129, wattage: 850, tags: 'ATX 3.1' },
            { name: '1000W 80+ Gold', price: 159, wattage: 1000, tags: 'ATX 3.1' }
        ],
        case: [
            { name: 'Lian Li O11 Vision', price: 149, tags: 'Mid Tower / ATX' },
            { name: 'NZXT H6 Flow RGB', price: 129, tags: 'Mid Tower / ATX' },
            { name: 'Fractal North', price: 119, tags: 'Mid Tower / ATX' }
        ],
        cooler: [
            { name: 'Noctua NH-D15', price: 109, tags: 'Air / Dual Tower' },
            { name: 'Arctic Liquid Freezer III 360', price: 89, tags: 'AIO / 360mm' },
            { name: 'DeepCool AK620', price: 54, tags: 'Air / Dual Tower' }
        ]
    },

    categoryLabels: {
        cpu: 'CPU',
        motherboard: 'Motherboard',
        gpu: 'GPU',
        ram: 'RAM',
        storage: 'Storage',
        psu: 'PSU',
        case: 'Case',
        cooler: 'CPU Cooler'
    },

    openSelector(category) {
        this.currentCategory = category;
        this.search = '';
        this.componentModal = true;
    },

    selectComponent(category, component) {
        this.selected[category] = component;
        this.componentModal = false;
        this.persistSelection();
        this.validateBuild();
        this.refreshFps();
        this.refreshLivePrice();
    },

    validateBuild() {
        const cpu = this.selected.cpu;
        const board = this.selected.motherboard;
        const gpu = this.selected.gpu;
        const psu = this.selected.psu;
        const ram = this.selected.ram;

        this.compatibility.cpuMotherboard = !cpu || !board || cpu.socket === board.socket;

        this.compatibility.ramSupported = !ram || (board && ram.supported !== false);

        const gpuWattage = gpu ? gpu.wattage : 0;
        const psuWattage = psu ? psu.wattage : 0;
        this.compatibility.powerEnough = !gpu || !psu || psuWattage >= gpuWattage + 200;

        this.compatibility.gpuClearance = !gpu;

        this.validateServerSide();
    },

    async validateServerSide() {
        try {
            const response = await fetch(this.endpoints.validate, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ selection: this.selected })
            });

            if (!response.ok) return;

            this.compatibility = await response.json();
        } catch (e) {
            // Keep the instant client-side checks.
        }
    },

    async refreshLivePrice() {
        const components = Object.entries(this.selected)
            .filter(([category, item]) => item && item.id)
            .map(([category, item]) => ({ category, id: item.id }));

        if (!components.length) {
            this.livePrice = null;
            return;
        }

        try {
            const response = await fetch(this.endpoints.price, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ selection: this.selected })
            });

            if (!response.ok) return;

            this.livePrice = await response.json();
        } catch (e) {
            // Keep the last known price.
        }
    },

    toggleViewport() {
        this.viewportOpen = !this.viewportOpen;
        if (this.viewportOpen) {
            // Let the x-show'd container render before mounting.
            requestAnimationFrame(() => this.initViewport());
        }
    },

    initViewport() {
        if (!window.mountPcViewport) return;

        const el = document.getElementById('pc-viewport');
        if (!el) return;

        if (this.viewportHandle) {
            this.viewportHandle.assemble();
            return;
        }

        // Read the live selection through a closure so the viewport can be
        // re-assembled as the user swaps parts.
        this.viewportHandle = window.mountPcViewport(el, () => this.selected, {
            onDimsChange: (parts) => this.refreshViewportParts(),
        });

        // Keep the part list + dims panel in sync on every catalog refresh.
        this.refreshViewportParts();
    },

    refreshViewportParts() {
        const categories = ['case', 'gpu', 'cpu', 'motherboard', 'ram', 'storage', 'psu', 'cooler'];
        const catalog = window.pctgCatalog || {};

        this.viewportParts = categories
            .map((category) => {
                const picked = this.selected[category];
                if (!picked?.id) return null;
                const full = (catalog[category] || []).find((c) => c.id === picked.id) || picked;
                return {
                    category,
                    name: full.name,
                    dims: full.dims || null
                };
            })
            .filter(Boolean);
    },

    snapshotViewport() {
        if (this.viewportHandle) {
            this.viewportHandle.snapshot();
        }
    },

    renderStorefront() {
        if (!this.viewportHandle) return;

        // Build the parts payload from the live selection (names, not ids).
        const parts = {};
        for (const [category, item] of Object.entries(this.selected)) {
            if (item && item.name) parts[category] = item.name;
        }

        this.rendering3d = true;
        this.render3dError = null;
        this.render3dUrl = null;

        this.viewportHandle.renderStorefront({ prompt: '', parts, denoise: 0.42 })
            .then((url) => { this.render3dUrl = url; this.rendering3d = false; })
            .catch((e) => { this.render3dError = e.message || 'Render failed'; this.rendering3d = false; });
    },

    downloadUrl(url) {
        const a = document.createElement('a');
        a.href = url;
        a.download = `pctg-build-render-${Date.now()}.png`;
        a.target = '_blank';
        document.body.appendChild(a);
        a.click();
        a.remove();
    },

    viewportHint() {
        if (!this.viewportOpen) return '';
        const count = this.viewportParts.length;
        return count
            ? `${count} component${count === 1 ? '' : 's'} rendered · dimensions in millimetres`
            : 'Select components to see them rendered.';
    },

    async refreshFps() {
        const gpu = this.selected.gpu;

        if (!gpu) {
            this.fpsResults = [];
            return;
        }

        const cpu = this.selected.cpu;
        const params = new URLSearchParams({ gpu_id: gpu.id, resolution: this.resolution });

        if (cpu && cpu.id) {
            params.set('cpu_id', cpu.id);
        }

        try {
            const response = await fetch(this.endpoints.fps + '?' + params);
            if (!response.ok) return;
            this.fpsResults = await response.json();
        } catch (e) {
            this.fpsResults = [];
        }
    },

    async generateBuild() {
        this.loading = true;

        try {
            // Clamp before we spend a request on a budget we know cannot build.
            this.applyBudgetFloor();

            const response = await fetch(this.endpoints.ai, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    budget: this.budget,
                    purpose: this.purpose,
                    resolution: this.resolution
                })
            });

            if (!response.ok) return;

            const data = await response.json();

            this.aiRecommendation = data.budget || data;
            this.aiIdealBuild = data.ideal || null;

            // The server is the authority on the minimum. If it clamped a
            // budget this client had not yet corrected, adopt its number and
            // its wording so the field and the explanation can never disagree.
            if (data.notice && data.notice.raised) {
                this.budget = data.budget_used ?? data.notice.min;
                this.budgetNotice = {
                    raised: true,
                    min: data.notice.min,
                    label: data.notice.label,
                    message: data.notice.message,
                    hybrid: data.notice.hybrid
                };
            }

            const build = this.aiRecommendation;

            if (build.complete === false) {
                this.missingWarning = ['incomplete-ai-build'];
                return;
            }

            for (const [category, component] of Object.entries(build.components || {})) {
                this.selected[category] = component;
            }

            this.persistSelection();
            this.validateBuild();
            this.refreshFps();
            this.refreshLivePrice();

            setTimeout(() => {
                document.getElementById('build-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 150);
        } catch (e) {
            // Ignore; keep current selection.
        } finally {
            this.loading = false;
        }
    },

    async saveBuild() {
        if (!this.buildComplete) {
            this.showMissingWarning();
            return;
        }

        const components = Object.entries(this.selected)
            .filter(([category, item]) => item && item.id)
            .map(([category, item]) => ({ category, id: item.id }));

        if (!components.length) return;

        this.saving = true;
        this.savedUrl = null;

        try {
            const response = await fetch(this.endpoints.builds, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name: this.saveName(),
                    purpose: this.purpose,
                    resolution: this.resolution,
                    budget: this.budget,
                    components
                })
            });

            if (!response.ok) return;

            const data = await response.json();
            this.savedUrl = data.share_url;
            await this.loadBuilds();
        } catch (e) {
            // Ignore; keep the in-memory build.
        } finally {
            this.saving = false;
        }
    },

    async loadBuilds() {
        try {
            const response = await fetch(this.endpoints.builds, {
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) return;
            this.savedBuilds = await response.json();
        } catch (e) {
            this.savedBuilds = [];
        }
    },

    loadBuild(id) {
        const build = this.savedBuilds.find(b => b.id == id);
        if (!build) return;

        const next = {};

        for (const item of build.components) {
            next[item.category] = item;
        }

        for (const category of Object.keys(this.selected)) {
            this.selected[category] = next[category] || null;
        }

        if (build.purpose) this.purpose = build.purpose;
        if (build.resolution) this.resolution = build.resolution;
        if (build.budget !== null && build.budget !== undefined) {
            this.budget = Number(build.budget);
        }

        this.loadedBuild = build;
        this.persistSelection();
        this.validateBuild();
        this.refreshFps();
        this.refreshLivePrice();
    },

    saveName() {
        const label = this.purpose || 'Custom';
        return label.charAt(0).toUpperCase() + label.slice(1) + ' Build';
    },

    buildCost() {
        return Object.values(this.selected)
            .filter(Boolean)
            .reduce((sum, item) => sum + item.price, 0);
    },

    filteredComponents() {
        const q = (this.search || '').toLowerCase();
        const list = this.catalog[this.currentCategory] || [];

        if (!q) {
            return list;
        }

        return list.filter(item =>
            (item.name + ' ' + (item.tags || '')).toLowerCase().includes(q)
        );
    },

    categoryLabel(category) {
        return this.categoryLabels[category] || 'Component';
    },

    applyBuild(build) {
        if (!build) return;

        if (build.complete === false) {
            this.missingWarning = ['incomplete-ai-build'];
            return;
        }

        for (const [category, component] of Object.entries(build.components || {})) {
            this.selected[category] = component;
        }

        this.persistSelection();
        this.validateBuild();
        this.refreshFps();
        this.refreshLivePrice();
    },

    allIdealApplied() {
        if (!this.aiIdealBuild) return false;

        for (const [category, ideal] of Object.entries(this.aiIdealBuild.components)) {
            const current = this.selected[category];
            if (!current || !ideal || current.id !== ideal.id) {
                return false;
            }
        }

        return true;
    },

    healthScore() {
        let score = 0;
        let total = 0;

        const checks = this.compatibility || {};
        for (const key of ['cpuMotherboard', 'ramSupported', 'powerEnough', 'gpuClearance']) {
            total += 1;
            if (checks[key]) score += 1;
        }

        const filled = Object.values(this.selected).filter(Boolean).length;
        const categories = Object.keys(this.selected).length;
        score += filled / categories;

        return Math.round((score / (total + 1)) * 100);
    },

    healthLabel() {
        const s = this.healthScore();
        if (s >= 80) return 'Excellent Build Balance';
        if (s >= 60) return 'Good Build Balance';
        if (s >= 40) return 'Build Needs Attention';
        return 'Build Incomplete';
    }

});
