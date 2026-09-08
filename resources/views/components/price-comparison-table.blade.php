{{-- Price Comparison Table Component --}}
<div x-data="priceComparison()" class="w-full">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
        {{-- Header --}}
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Price Comparison</h3>
                <div class="flex gap-2">
                    <select x-model="sortBy" @change="sortResults()" class="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 text-sm">
                        <option value="price_asc">Price: Low to High</option>
                        <option value="price_desc">Price: High to Low</option>
                        <option value="rating">Rating</option>
                        <option value="reviews">Most Reviews</option>
                    </select>
                    <button @click="refreshPrices()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                        🔄 Refresh
                    </button>
                </div>
            </div>
        </div>

        {{-- Loading State --}}
        <div x-show="loading" class="p-8 text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <p class="mt-2 text-gray-500">Comparing prices...</p>
        </div>

        {{-- Results Table --}}
        <div x-show="!loading && results.length > 0" class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Retailer</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Price</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Rating</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Availability</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-for="(result, index) in results" :key="index">
                        <tr :class="index === 0 ? 'bg-green-50 dark:bg-green-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-700'">
                            <td class="px-4 py-4">
                                <div class="flex items-center">
                                    <img :src="result.retailerLogo" class="w-8 h-8 rounded mr-2" :alt="result.retailer" onerror="this.style.display='none'">
                                    <span class="font-medium text-gray-900 dark:text-white" x-text="result.retailer"></span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex items-center">
                                    <img :src="result.image" class="w-12 h-12 object-contain mr-3" :alt="result.name" onerror="this.style.display='none'">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white line-clamp-2" x-text="result.name"></p>
                                        <p class="text-xs text-gray-500" x-text="result.sku || ''"></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div>
                                    <span class="text-lg font-bold text-green-600" x-text="'$' + result.price"></span>
                                    <span x-show="result.originalPrice && result.originalPrice > result.price" class="ml-2 text-sm text-gray-500 line-through" x-text="'$' + result.originalPrice"></span>
                                    <span x-show="result.discount" class="ml-2 px-2 py-0.5 bg-red-100 text-red-800 text-xs rounded-full" x-text="'-' + result.discount + '%'"></span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div x-show="result.rating" class="flex items-center">
                                    <span class="text-yellow-500">★</span>
                                    <span class="ml-1 text-sm text-gray-700 dark:text-gray-300" x-text="result.rating"></span>
                                    <span class="text-xs text-gray-500 ml-1" x-text="'(' + result.reviews + ')'"></span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <span :class="result.inStock ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 py-1 text-xs font-medium rounded-full" x-text="result.inStock ? 'In Stock' : 'Out of Stock'"></span>
                            </td>
                            <td class="px-4 py-4">
                                <a :href="result.url" target="_blank" class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
                                    Buy →
                                </a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Empty State --}}
        <div x-show="!loading && results.length === 0" class="p-8 text-center">
            <div class="text-4xl mb-4">🔍</div>
            <p class="text-gray-500">Search for a product to compare prices across retailers</p>
        </div>
    </div>
</div>

<script>
function priceComparison() {
    return {
        results: [],
        loading: false,
        sortBy: 'price_asc',

        async comparePrices(query) {
            this.loading = true;
            try {
                const [amazonRes, neweggRes, ebayRes] = await Promise.all([
                    fetch(`/api/components/search/amazon?query=${encodeURIComponent(query)}`),
                    fetch(`/api/components/search/newegg?query=${encodeURIComponent(query)}`),
                    fetch(`/api/components/search/ebay?query=${encodeURIComponent(query)}`),
                ]);

                const [amazon, newegg, ebay] = await Promise.all([
                    amazonRes.json(),
                    neweggRes.json(),
                    ebayRes.json(),
                ]);

                this.results = [
                    ...(amazon.data || []).map(p => ({ ...p, retailer: 'Amazon', retailerLogo: '/images/retailers/amazon.png' })),
                    ...(newegg.data || []).map(p => ({ ...p, retailer: 'Newegg', retailerLogo: '/images/retailers/newegg.png' })),
                    ...(ebay.data || []).map(p => ({ ...p, retailer: 'eBay', retailerLogo: '/images/retailers/ebay.png' })),
                ];

                this.sortResults();
            } catch (e) {
                console.error('Comparison error:', e);
            } finally {
                this.loading = false;
            }
        },

        sortResults() {
            this.results.sort((a, b) => {
                switch (this.sortBy) {
                    case 'price_asc': return parseFloat(a.price) - parseFloat(b.price);
                    case 'price_desc': return parseFloat(b.price) - parseFloat(a.price);
                    case 'rating': return parseFloat(b.rating || 0) - parseFloat(a.rating || 0);
                    case 'reviews': return parseInt(b.reviews || 0) - parseInt(a.reviews || 0);
                    default: return 0;
                }
            });
        },

        async refreshPrices() {
            if (this.results.length > 0) {
                const query = this.results[0].name;
                await this.comparePrices(query);
            }
        }
    };
}
</script>
