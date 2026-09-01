@props(['category', 'label'])

<div class="pctg-card-hover">
    <div class="flex justify-between">
        <div>
            <h3 class="font-semibold">
                {{ $label }}
            </h3>

            <p
                class="mt-1 text-sm text-slate-400"
                x-text="selected['{{ $category }}'] ? selected['{{ $category }}'].name : 'No {{ $label }} Selected'"
            ></p>
        </div>

        <span
            class="font-bold text-red-400"
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
