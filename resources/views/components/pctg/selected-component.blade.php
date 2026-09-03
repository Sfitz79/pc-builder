@props(['category', 'label'])

<div class="pctg-card-hover">
    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-700 bg-slate-800/50">
                <template x-if="selected['{{ $category }}'] && selected['{{ $category }}'].image">
                    <img
                        :src="selected['{{ $category }}'].image"
                        alt=""
                        loading="lazy"
                        class="h-full w-full object-contain"
                        @@error="$el.closest('span').classList.add('img-fallback')"
                    >
                </template>
            </span>

            <div class="min-w-0">
                <h3 class="font-semibold">
                    {{ $label }}
                </h3>

                <p
                    class="mt-1 truncate text-sm text-slate-400"
                    x-text="selected['{{ $category }}'] ? selected['{{ $category }}'].name : 'No {{ $label }} Selected'"
                ></p>
            </div>
        </div>

        <span
            class="shrink-0 font-bold text-red-400"
            x-text="selected['{{ $category }}'] ? '£' + selected['{{ $category }}'].price : '£0'"
        ></span>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <button
            type="button"
            class="pctg-button-secondary"
            @click="openSelector('{{ $category }}')"
            x-text="selected['{{ $category }}'] ? 'Change {{ $label }}' : 'Select {{ $label }}'"
        ></button>
    </div>
</div>
