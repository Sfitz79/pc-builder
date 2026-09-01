window.builderState = () => ({

    selected: {
        cpu: null,
        motherboard: null,
        gpu: null,
        ram: null,
        storage: null,
        psu: null,
        case: null
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

    loading: false,

    saving: false,

    savedUrl: null,

    savedBuilds: [],

    selectedBuildId: '',

    loadedBuild: null,

    endpoints: {
        catalog: '/builder/catalog',
        fps: '/builder/fps',
        ai: '/builder/ai',
        validate: '/builder/validate',
        builds: '/builder/builds'
    },

    init() {
        this.loadCatalog();
        this.loadBuilds();
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
        this.persistSelection();
        window.location.href = '/builder/checkout';
    },

    async loadCatalog() {
        try {
            const response = await fetch(this.endpoints.catalog);
            if (!response.ok) return;

            const data = await response.json();
            const loaded = {};

            for (const category of Object.keys(this.catalog)) {
                if (Array.isArray(data[category]) && data[category].length) {
                    loaded[category] = data[category];
                }
            }

            if (Object.keys(loaded).length) {
                this.catalog = loaded;
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
        ]
    },

    categoryLabels: {
        cpu: 'CPU',
        motherboard: 'Motherboard',
        gpu: 'GPU',
        ram: 'RAM',
        storage: 'Storage',
        psu: 'PSU',
        case: 'Case'
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
            this.aiRecommendation = data;

            for (const [category, component] of Object.entries(data.components || {})) {
                this.selected[category] = component;
            }

            this.persistSelection();
            this.validateBuild();
            this.refreshFps();

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
    }

});
