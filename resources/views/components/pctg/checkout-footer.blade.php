@props([
    'buildTotal' => null,
    'href' => '/builder/checkout',
    'live' => false,
])

<footer
    class="
        sticky
        bottom-0
        z-40
        border-t
        border-slate-800
        bg-[#0b0d12]/90
        backdrop-blur-xl
    "
>
    <div
        class="
            flex
            flex-wrap
            items-center
            justify-between
            gap-4
            px-4
            py-4
            lg:px-6
        "
    >
        <div>
            <p class="text-xs text-slate-500">Estimated Total</p>
            @if ($live)
                <p class="text-2xl font-bold" x-text="$store.checkout.totalLabel"></p>
            @else
                <p class="text-2xl font-bold">{{ $buildTotal ?? '&mdash;' }}</p>
            @endif
        </div>

        <div class="hidden text-xs text-slate-500 md:block">
@if ($live)
                Built &amp; tested in the UK &middot; UK delivery included &middot; Two-year warranty
            @else
                Build ready &middot; UK delivery included &middot; Two-year warranty
            @endif
        </div>

        <x-pctg.button :href="$href">
            Checkout
        </x-pctg.button>
    </div>
</footer>
