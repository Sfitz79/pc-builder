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
                :key="item.id || item.name"
            >

                <button
                    class="pctg-card-hover flex w-full items-center gap-3 text-left"
                    @click="selectComponent(currentCategory, item)"
                >

                    <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-700 bg-slate-800/50">
                        <template x-if="item.image">
                            <img
                                :src="item.image"
                                alt=""
                                loading="lazy"
                                class="h-full w-full object-contain"
                                @@error="$el.closest('span').classList.add('img-fallback')"
                            >
                        </template>
                        <template x-if="!item.image">
                            <x-pctg.icon name="cpu" class="h-6 w-6 text-slate-500" />
                        </template>
                    </span>

                    <span class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold" x-text="item.name"></h3>

                        <p
                            class="mt-1 truncate text-xs text-slate-400"
                            x-text="item.tags || ''"
                        ></p>

                        <p class="mt-2 font-bold text-red-400" x-text="'£' + item.price"></p>
                    </span>

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
