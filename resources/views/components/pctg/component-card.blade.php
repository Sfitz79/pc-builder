@props([
    'title',
    'subtitle',
    'price' => null,
    'image' => null
])

<x-pctg.hover-card>
    <div class="flex items-start gap-3">
        {{--
            The image prop is a URL, not markup.

            It used to be echoed with {{ $image }}, which ESCAPES. So anyone
            passing a sensible <img src="..."> got that tag printed to the
            customer as literal text, and anyone passing a URL got a bare URL
            printed. The prop was effectively unusable. The tag is built here,
            with the src escaped as a normal Blade expression, and the box
            matches selected-component.blade.php so the design system stays
            consistent.
        --}}
        @if ($image)
            <span
                class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-700 bg-slate-800/50"
            >
                <img
                    src="{{ $image }}"
                    alt=""
                    loading="lazy"
                    class="h-full w-full object-contain"
                >
            </span>
        @endif

        <div class="flex min-w-0 flex-1 justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-lg font-semibold">
                    {{ $title }}
                </h3>

                <p class="text-sm text-slate-400">
                    {{ $subtitle }}
                </p>
            </div>

            @if ($price)
                <span class="shrink-0 font-bold text-red-400">
                    £{{ $price }}
                </span>
            @endif
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        {{ $slot }}
    </div>
</x-pctg.hover-card>
