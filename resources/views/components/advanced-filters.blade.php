{{-- Advanced Filters Component --}}
<div x-data="advancedFilters()" class="w-full">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Filters</h3>
            <button @click="resetFilters()" class="text-sm text-blue-600 hover:text-blue-800">Reset All</button>
        </div>

        {{-- Category Filter --}}
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Category</label>
            <div class="flex flex-wrap gap-2">
                <template x-for="cat in categories" :key="cat.id">
                    <button
                        @click="toggleCategory(cat.id)"
                        :class="selectedCategories.includes(cat.id) ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                        class="px-3 py-1 rounded-full text-sm font-medium transition-colors"
                        x-text="cat.name"
                    ></button>
                </template>
            </div>
        </div>

        {{-- Price Range --}}
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Price Range</label>
            <div class="flex items-center gap-4">
                <input type="number" x-model="priceMin" placeholder="Min" class="w-24 px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 text-sm">
                <span class="text-gray-500">-</span>
                <input type="number" x-model="priceMax" placeholder="Max" class="w-24 px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 text-sm">
            </div>
            <input type="range" x-model="priceRange" min="0" max="5000" step="50" class="w-full mt-2" @input="updatePriceRange()">
            <div class="flex justify-between text-xs text-gray-500">
                <span>$0</span>
                <span x-text="'$' + priceRange"></span>
                <span>$5,000</span>
            </div>
        </div>

        {{-- Brand Filter --}}
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Brand</label>
            <div class="flex flex-wrap gap-2">
                <template x-for="brand in brands" :key="brand">
                    <button
                        @click="toggleBrand(brand)"
                        :class="selectedBrands.includes(brand) ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                        class="px-3 py-1 rounded-full text-sm font-medium transition-colors"
                        x-text="brand"
                    ></button>
                </template>
            </div>
        </div>

        {{-- Rating Filter --}}
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Minimum Rating</label>
            <div class="flex gap-2">
                <template x-for="star in [1, 2, 3, 4, 5]" :key="star">
                    <button
                        @click="minRating = star"
                        :class="minRating >= star ? 'text-yellow-500' : 'text-gray-300'"
                        class="text-2xl"
                    >★</button>
                </template>
            </div>
        </div>

        {{-- In Stock Only --}}
        <div class="mb-4">
            <label class="flex items-center">
                <input type="checkbox" x-model="inStockOnly" class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">In Stock Only</span>
            </label>
        </div>

        {{-- Apply Filters --}}
        <button @click="applyFilters()" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Apply Filters
        </button>
    </div>
</div>

<script>
function advancedFilters() {
    return {
        categories: [
            { id: 'cpu', name: 'CPU' },
            { id: 'gpu', name: 'GPU' },
            { id: 'motherboard', name: 'Motherboard' },
            { id: 'ram', name: 'RAM' },
            { id: 'storage', name: 'Storage' },
            { id: 'psu', name: 'PSU' },
            { id: 'case', name: 'Case' },
            { id: 'cooler', name: 'Cooler' },
        ],
        selectedCategories: [],
        priceMin: '',
        priceMax: '',
        priceRange: 1000,
        brands: ['AMD', 'Intel', 'NVIDIA', 'Corsair', 'G.Skill', 'Samsung', 'Western Digital', 'Seagate', 'EVGA', 'ASUS', 'MSI', 'Gigabyte'],
        selectedBrands: [],
        minRating: 0,
        inStockOnly: false,

        toggleCategory(id) {
            const index = this.selectedCategories.indexOf(id);
            if (index === -1) {
                this.selectedCategories.push(id);
            } else {
                this.selectedCategories.splice(index, 1);
            }
        },

        toggleBrand(brand) {
            const index = this.selectedBrands.indexOf(brand);
            if (index === -1) {
                this.selectedBrands.push(brand);
            } else {
                this.selectedBrands.splice(index, 1);
            }
        },

        updatePriceRange() {
            this.priceMax = this.priceRange;
        },

        resetFilters() {
            this.selectedCategories = [];
            this.priceMin = '';
            this.priceMax = '';
            this.priceRange = 1000;
            this.selectedBrands = [];
            this.minRating = 0;
            this.inStockOnly = false;
        },

        applyFilters() {
            const filters = {
                categories: this.selectedCategories,
                priceMin: this.priceMin,
                priceMax: this.priceMax || this.priceRange,
                brands: this.selectedBrands,
                minRating: this.minRating,
                inStockOnly: this.inStockOnly,
            };
            this.$dispatch('filters-applied', filters);
        }
    };
}
</script>
