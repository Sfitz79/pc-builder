<div
    x-data="{ open: false }"
    x-on:open-mobile-menu.window="open = true"
>
    <div
        x-show="open"
        class="fixed inset-0 z-50 bg-black/60"
        x-on:click="open = false"
    ></div>

    <aside
        x-show="open"
        class="
            fixed
            left-0
            top-0
            z-50
            h-full
            w-72
            border-r
            border-slate-800
            bg-[#171a21]
        "
    >
        <div class="p-6">
            <h2 class="mb-4 text-xl font-bold">Components</h2>

            <div class="space-y-3">
                <x-pctg.sidebar-item active>
                    CPU
                </x-pctg.sidebar-item>

                <x-pctg.sidebar-item>
                    GPU
                </x-pctg.sidebar-item>

                <x-pctg.sidebar-item>
                    RAM
                </x-pctg.sidebar-item>

                <x-pctg.sidebar-item>
                    Storage
                </x-pctg.sidebar-item>
            </div>
        </div>
    </aside>
</div>
