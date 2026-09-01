<div
    x-show="componentModal"
    x-transition
    x-cloak
    class="fixed inset-0 z-[999]"
>

    <div
        class="absolute inset-0 bg-black/70 backdrop-blur-sm"
        @click="componentModal = false"
    ></div>

    <div
        class="absolute inset-x-0 top-16 mx-auto max-w-5xl rounded-3xl border border-slate-800 bg-[#171a21] p-6"
    >

        <div class="flex items-center justify-between">

            <h2 class="text-2xl font-bold">
                <span x-text="categoryLabel(currentCategory)"></span>
                <span class="text-red-500">Selector</span>
            </h2>

            <button
                class="rounded-full bg-slate-800 px-3 py-1 text-slate-400 hover:bg-slate-700 hover:text-white"
                @click="componentModal = false"
            >
                ✕
            </button>

        </div>

        <input
            type="text"
            placeholder="Search Components"
            class="pctg-input mt-5"
            x-model="search"
        >

        <div class="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">

            <template
                x-for="item in filteredComponents()"
                :key="item.name"
            >

                <button
                    class="pctg-card-hover text-left"
                    @click="selectComponent(currentCategory, item)"
                >

                    <h3 class="font-semibold" x-text="item.name"></h3>

                    <p
                        class="mt-1 text-xs text-slate-400"
                        x-text="item.tags || ''"
                    ></p>

                    <p class="mt-2 font-bold text-red-400" x-text="'£' + item.price"></p>

                </button>

            </template>

        </div>

        <p
            class="mt-6 text-center text-sm text-slate-400"
            x-show="filteredComponents().length === 0"
        >
            No components match your search.
        </p>

    </div>

</div>
