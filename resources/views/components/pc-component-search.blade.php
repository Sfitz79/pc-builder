<div x-data="pcComponentSearch()" class="w-full">
    <!-- Search Input -->
    <div class="mb-4">
        <label for="component-search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            Search PC Components
        </label>
        <div class="flex gap-2">
            <input
                type="text"
                id="component-search"
                x-model="searchQuery"
                @keyup.enter="search()"
                placeholder="e.g., RTX 4070, Ryzen 7 7800X3D, DDR5 RAM..."
                class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >
            <button
                @click="search()"
                :disabled="loading"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <span x-show="!loading">Search</span>
                <span x-show="loading" class="flex items-center">
                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Searching...
                </span>
            </button>
        </div>
    </div>

    <!-- Source Tabs -->
    <div class="flex gap-2 mb-4">
        <button
            @click="source = 'amazon'; search()"
            :class="source === 'amazon' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
            class="px-3 py-1 rounded-lg text-sm font-medium transition-colors"
        >
            Amazon
        </button>
        <button
            @click="source = 'newegg'; search()"
            :class="source === 'newegg' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
            class="px-3 py-1 rounded-lg text-sm font-medium transition-colors"
        >
            Newegg
        </button>
        <button
            @click="source = 'ebay'; search()"
            :class="source === 'ebay' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
            class="px-3 py-1 rounded-lg text-sm font-medium transition-colors"
        >
            eBay
        </button>
    </div>

    <!-- Storage Pricing Quick Access -->
    <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
        <h3 class="text-sm font-medium text-green-800 dark:text-green-200 mb-2">Quick Storage Prices</h3>
        <div class="flex gap-2">
            <button
                @click="getStoragePricing('SSD')"
                class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700"
            >
                Cheapest SSDs
            </button>
            <button
                @click="getStoragePricing('HDD')"
                class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700"
            >
                Cheapest HDDs
            </button>
        </div>
    </div>

    <!-- Results -->
    <div x-show="results.length > 0" class="mt-4">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">
            <span x-text="results.length"></span> results from <span x-text="source"></span>
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <template x-for="(item, index) in results" :key="index">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-200 dark:border-gray-700">
                    <template x-if="item.image">
                        <img :src="item.image" :alt="item.title" class="w-full h-32 object-contain mb-3 rounded">
                    </template>
                    <h4 class="font-medium text-gray-900 dark:text-white text-sm line-clamp-2 mb-2" x-text="item.title || item.name || 'Product'"></h4>
                    <div class="flex justify-between items-center">
                        <span class="text-lg font-bold text-green-600 dark:text-green-400" x-text="item.price || item.currentPrice || 'N/A'"></span>
                        <template x-if="item.url || item.productUrl">
                            <a
                                :href="item.url || item.productUrl"
                                target="_blank"
                                class="text-blue-600 dark:text-blue-400 text-sm hover:underline"
                            >
                                View →
                            </a>
                        </template>
                    </div>
                    <template x-if="item.rating">
                        <div class="mt-2 text-sm text-yellow-600">
                            ★ <span x-text="item.rating"></span>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <!-- Error Message -->
    <div x-show="error" class="mt-4 p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
        <p class="text-red-600 dark:text-red-400 text-sm" x-text="error"></p>
    </div>
</div>

<script>
function pcComponentSearch() {
    return {
        searchQuery: '',
        source: 'amazon',
        results: [],
        loading: false,
        error: null,

        async search() {
            if (!this.searchQuery.trim()) {
                this.error = 'Please enter a search query';
                return;
            }

            this.loading = true;
            this.error = null;
            this.results = [];

            try {
                const response = await fetch(`/api/components/search/${this.source}?query=${encodeURIComponent(this.searchQuery)}`);
                const data = await response.json();

                if (data.success) {
                    this.results = data.data;
                } else {
                    this.error = data.error || 'Search failed';
                }
            } catch (e) {
                this.error = 'Network error: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        async getStoragePricing(technology) {
            this.loading = true;
            this.error = null;
            this.results = [];

            try {
                const response = await fetch(`/api/components/storage/pricing?technology=${technology}&limit=10`);
                const data = await response.json();

                if (data.success) {
                    this.results = data.data.map(item => ({
                        title: item.name,
                        price: `$${item.price}`,
                        image: item.image_url,
                        url: item.url,
                        rating: null,
                    }));
                    this.source = 'pricepergig';
                } else {
                    this.error = data.error || 'Failed to get pricing';
                }
            } catch (e) {
                this.error = 'Network error: ' + e.message;
            } finally {
                this.loading = false;
            }
        }
    };
}
</script>
