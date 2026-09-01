@props([
    'title',
    'subtitle',
    'price',
    'image' => null
])

<x-pctg.hover-card>
    @if ($image)
        {{ $image }}
    @endif

    <div class="flex justify-between">
        <div>
            <h3 class="text-lg font-semibold">
                {{ $title }}
            </h3>

            <p class="text-sm text-slate-400">
                {{ $subtitle }}
            </p>
        </div>

        <span class="font-bold text-red-400">
            £{{ $price }}
        </span>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        {{ $slot }}
    </div>
</x-pctg.hover-card>
