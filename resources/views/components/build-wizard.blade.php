{{-- Build Wizard Component --}}
<div x-data="buildWizard()" class="w-full">
    {{-- Progress Steps --}}
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <template x-for="(step, index) in steps" :key="index">
                <div class="flex items-center" :class="index < steps.length - 1 ? 'flex-1' : ''">
                    <div class="flex items-center">
                        <div
                            class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold transition-all duration-300"
                            :class="currentStep > index ? 'bg-green-500 text-white' : currentStep === index ? 'bg-blue-600 text-white ring-4 ring-blue-200' : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400'"
                        >
                            <span x-show="currentStep <= index" x-text="index + 1"></span>
                            <span x-show="currentStep > index">✓</span>
                        </div>
                        <span class="ml-2 text-sm font-medium hidden sm:block" :class="currentStep >= index ? 'text-gray-900 dark:text-white' : 'text-gray-500'" x-text="step"></span>
                    </div>
                    <div x-show="index < steps.length - 1" class="flex-1 mx-4 h-0.5" :class="currentStep > index ? 'bg-green-500' : 'bg-gray-200 dark:bg-gray-700'"></div>
                </div>
            </template>
        </div>
    </div>

    {{-- Step Content --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
        {{-- Step 1: CPU --}}
        <div x-show="currentStep === 0" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Select CPU (Processor)</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Choose your processor. This determines the socket type for your motherboard.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border-2 rounded-lg cursor-pointer transition-all"
                     :class="selectedParts.cpu ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                     @click="openPicker('cpu')">
                    <template x-if="selectedParts.cpu">
                        <div>
                            <img :src="selectedParts.cpu.image" class="w-full h-32 object-contain mb-2" :alt="selectedParts.cpu.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="selectedParts.cpu.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + selectedParts.cpu.price"></p>
                        </div>
                    </template>
                    <template x-if="!selectedParts.cpu">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-2">🔧</div>
                            <p class="text-gray-500">Click to select CPU</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Step 2: Motherboard --}}
        <div x-show="currentStep === 1" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Select Motherboard</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Choose a compatible motherboard based on your CPU socket.</p>
            <div x-show="selectedParts.cpu" class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg mb-4">
                <p class="text-sm text-blue-700 dark:text-blue-300">Required socket: <strong x-text="selectedParts.cpu?.socket || 'Any'"></strong></p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border-2 rounded-lg cursor-pointer transition-all"
                     :class="selectedParts.motherboard ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                     @click="openPicker('motherboard')">
                    <template x-if="selectedParts.motherboard">
                        <div>
                            <img :src="selectedParts.motherboard.image" class="w-full h-32 object-contain mb-2" :alt="selectedParts.motherboard.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="selectedParts.motherboard.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + selectedParts.motherboard.price"></p>
                        </div>
                    </template>
                    <template x-if="!selectedParts.motherboard">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-2">🔌</div>
                            <p class="text-gray-500">Click to select Motherboard</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Step 3: GPU --}}
        <div x-show="currentStep === 2" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Select Graphics Card</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Choose your GPU for gaming or professional work.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border-2 rounded-lg cursor-pointer transition-all"
                     :class="selectedParts.gpu ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                     @click="openPicker('gpu')">
                    <template x-if="selectedParts.gpu">
                        <div>
                            <img :src="selectedParts.gpu.image" class="w-full h-32 object-contain mb-2" :alt="selectedParts.gpu.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="selectedParts.gpu.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + selectedParts.gpu.price"></p>
                        </div>
                    </template>
                    <template x-if="!selectedParts.gpu">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-2">🎮</div>
                            <p class="text-gray-500">Click to select GPU</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Step 4: RAM --}}
        <div x-show="currentStep === 3" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Select Memory (RAM)</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Choose RAM compatible with your motherboard.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border-2 rounded-lg cursor-pointer transition-all"
                     :class="selectedParts.ram ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                     @click="openPicker('ram')">
                    <template x-if="selectedParts.ram">
                        <div>
                            <img :src="selectedParts.ram.image" class="w-full h-32 object-contain mb-2" :alt="selectedParts.ram.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="selectedParts.ram.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + selectedParts.ram.price"></p>
                        </div>
                    </template>
                    <template x-if="!selectedParts.ram">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-2">💾</div>
                            <p class="text-gray-500">Click to select RAM</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Step 5: Storage --}}
        <div x-show="currentStep === 4" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Select Storage</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Choose SSD or HDD for your operating system and files.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border-2 rounded-lg cursor-pointer transition-all"
                     :class="selectedParts.storage ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                     @click="openPicker('storage')">
                    <template x-if="selectedParts.storage">
                        <div>
                            <img :src="selectedParts.storage.image" class="w-full h-32 object-contain mb-2" :alt="selectedParts.storage.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="selectedParts.storage.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + selectedParts.storage.price"></p>
                        </div>
                    </template>
                    <template x-if="!selectedParts.storage">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-2">💿</div>
                            <p class="text-gray-500">Click to select Storage</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Step 6: PSU --}}
        <div x-show="currentStep === 5" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Select Power Supply</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Choose a PSU with enough wattage for your components.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border-2 rounded-lg cursor-pointer transition-all"
                     :class="selectedParts.psu ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                     @click="openPicker('psu')">
                    <template x-if="selectedParts.psu">
                        <div>
                            <img :src="selectedParts.psu.image" class="w-full h-32 object-contain mb-2" :alt="selectedParts.psu.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="selectedParts.psu.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + selectedParts.psu.price"></p>
                        </div>
                    </template>
                    <template x-if="!selectedParts.psu">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-2">⚡</div>
                            <p class="text-gray-500">Click to select PSU</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Step 7: Case --}}
        <div x-show="currentStep === 6" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Select Case</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-4">Choose a case that fits your motherboard and components.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border-2 rounded-lg cursor-pointer transition-all"
                     :class="selectedParts.case ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                     @click="openPicker('case')">
                    <template x-if="selectedParts.case">
                        <div>
                            <img :src="selectedParts.case.image" class="w-full h-32 object-contain mb-2" :alt="selectedParts.case.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="selectedParts.case.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + selectedParts.case.price"></p>
                        </div>
                    </template>
                    <template x-if="!selectedParts.case">
                        <div class="text-center py-8">
                            <div class="text-4xl mb-2">📦</div>
                            <p class="text-gray-500">Click to select Case</p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Step 8: Review --}}
        <div x-show="currentStep === 7" x-transition>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Review Your Build</h3>
            <div class="space-y-4">
                <template x-for="(part, type) in selectedParts" :key="type">
                    <div x-show="part" class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div class="flex items-center">
                            <img :src="part?.image" class="w-16 h-16 object-contain mr-4" :alt="part?.name">
                            <div>
                                <p class="text-sm text-gray-500 capitalize" x-text="type"></p>
                                <p class="font-semibold text-gray-900 dark:text-white" x-text="part?.name"></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-green-600" x-text="'$' + part?.price"></p>
                            <button @click="removePart(type)" class="text-sm text-red-500 hover:text-red-700">Remove</button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Total --}}
            <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <div class="flex justify-between items-center">
                    <span class="text-lg font-semibold text-gray-900 dark:text-white">Total Estimated Cost:</span>
                    <span class="text-2xl font-bold text-blue-600" x-text="'$' + totalPrice"></span>
                </div>
            </div>

            {{-- Compatibility Warnings --}}
            <div x-show="compatibilityWarnings.length > 0" class="mt-4 p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                <h4 class="font-semibold text-yellow-800 dark:text-yellow-200 mb-2">⚠️ Compatibility Notes</h4>
                <ul class="list-disc list-inside text-sm text-yellow-700 dark:text-yellow-300">
                    <template x-for="warning in compatibilityWarnings" :key="warning">
                        <li x-text="warning"></li>
                    </template>
                </ul>
            </div>
        </div>

        {{-- Navigation Buttons --}}
        <div class="flex justify-between mt-8">
            <button
                @click="prevStep()"
                x-show="currentStep > 0"
                class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
            >
                ← Previous
            </button>
            <div x-show="currentStep === 0"></div>
            <button
                @click="nextStep()"
                x-show="currentStep < steps.length - 1"
                :disabled="!canProceed"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                Next →
            </button>
            <button
                @click="saveBuild()"
                x-show="currentStep === steps.length - 1"
                :disabled="!allPartsSelected"
                class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                💾 Save Build
            </button>
        </div>
    </div>

    {{-- Part Picker Modal --}}
    <div x-show="showPicker" x-transition class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-gray-500 dark:bg-gray-900 bg-opacity-75" @click="closePicker()"></div>
            <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Select <span x-text="pickerType"></span></h3>
                    <button @click="closePicker()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
                </div>
                <input type="text" x-model="pickerSearch" @input="searchParts()" placeholder="Search parts..." class="w-full mb-4 p-3 border rounded-lg dark:bg-gray-700 dark:border-gray-600">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <template x-for="part in pickerResults" :key="part.id">
                        <div class="p-4 border rounded-lg cursor-pointer hover:border-blue-500 transition-all" @click="selectPart(part)">
                            <img :src="part.image" class="w-full h-32 object-contain mb-2" :alt="part.name">
                            <h4 class="font-semibold text-gray-900 dark:text-white" x-text="part.name"></h4>
                            <p class="text-green-600 font-bold" x-text="'$' + part.price"></p>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function buildWizard() {
    return {
        currentStep: 0,
        steps: ['CPU', 'Motherboard', 'GPU', 'RAM', 'Storage', 'PSU', 'Case', 'Review'],
        selectedParts: {
            cpu: null,
            motherboard: null,
            gpu: null,
            ram: null,
            storage: null,
            psu: null,
            case: null,
        },
        showPicker: false,
        pickerType: '',
        pickerSearch: '',
        pickerResults: [],

        get canProceed() {
            const requiredForStep = ['cpu', 'motherboard', 'gpu', 'ram', 'storage', 'psu', 'case'];
            return this.selectedParts[requiredForStep[this.currentStep]] !== null || this.currentStep === 7;
        },

        get allPartsSelected() {
            return Object.values(this.selectedParts).every(p => p !== null);
        },

        get totalPrice() {
            return Object.values(this.selectedParts)
                .filter(p => p !== null)
                .reduce((sum, p) => sum + parseFloat(p.price || 0), 0)
                .toFixed(2);
        },

        get compatibilityWarnings() {
            const warnings = [];
            if (this.selectedParts.cpu && this.selectedParts.motherboard) {
                if (this.selectedParts.cpu.socket !== this.selectedParts.motherboard.socket) {
                    warnings.push('CPU socket does not match motherboard socket!');
                }
            }
            return warnings;
        },

        nextStep() {
            if (this.canProceed && this.currentStep < this.steps.length - 1) {
                this.currentStep++;
            }
        },

        prevStep() {
            if (this.currentStep > 0) {
                this.currentStep--;
            }
        },

        openPicker(type) {
            this.pickerType = type;
            this.showPicker = true;
            this.pickerSearch = '';
            this.searchParts();
        },

        closePicker() {
            this.showPicker = false;
        },

        async searchParts() {
            try {
                const response = await fetch(`/api/components/search/amazon?query=${encodeURIComponent(this.pickerType + ' ' + this.pickerSearch)}&max_items=10`);
                const data = await response.json();
                this.pickerResults = data.data || [];
            } catch (e) {
                console.error('Search error:', e);
            }
        },

        selectPart(part) {
            this.selectedParts[this.pickerType] = part;
            this.closePicker();
        },

        removePart(type) {
            this.selectedParts[type] = null;
        },

        saveBuild() {
            const build = {
                parts: this.selectedParts,
                totalPrice: this.totalPrice,
                timestamp: new Date().toISOString(),
            };
            localStorage.setItem('pcBuilderBuild', JSON.stringify(build));
            alert('Build saved!');
        }
    };
}
</script>
