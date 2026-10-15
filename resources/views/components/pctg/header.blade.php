@props([
    'active' => 'builder',

    // Which product line this header belongs to. Business gets its own
    // sub-brand lockup: "PCTG Builder / Get Your Gamers Edge™" on a
    // workstation quote is the wrong brand, and the accent goes blue with it.
    'segment' => 'gaming',
])

@php
    $isBusiness = $segment === 'business';

    // The business builder is a different product, so its nav points at
    // business destinations. "AI Builder" and "Guides" are gaming-only pages
    // and are dropped rather than relabelled.
    $links = $isBusiness
        ? [
            'builder' => ['Business Builder', route('business.build')],
            'business' => ['Business Range', route('business')],
            'builds' => ['Saved Builds', route('builder.builds')],
            'checkout' => ['Checkout', route('builder.checkout')],
            'support' => ['Contact', route('support')],
        ]
        : [
            'builder' => ['Builder', route('builder')],
            'ai' => ['AI Builder', route('builder.generate')],
            'builds' => ['Saved Builds', route('builder.builds')],
            'checkout' => ['Checkout', route('builder.checkout')],
            'guides' => ['Guides', url('/best-gaming-pc-under-1500')],
        ];
@endphp

<header
    x-data="{ scrolled: false }"
    x-init="scrolled = window.scrollY > 12"
    @scroll.window.passive="scrolled = window.scrollY > 12"
    :class="scrolled ? 'h-12 bg-black/85 shadow-lg shadow-black/40' : 'h-16 bg-black/50'"
    class="
        sticky
        top-0
        z-50
        border-b
        border-slate-800
        backdrop-blur-xl
        transition-all
        duration-300
    "
>
    <div
        class="
            flex
            h-full
            items-center
            justify-between
            px-4
            lg:px-6
        "
    >
        <div class="flex items-center gap-4">
            <button
                x-on:click="$dispatch('open-mobile-menu')"
                class="text-slate-300 transition hover:text-white lg:hidden"
                aria-label="Open menu"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <a href="{{ $isBusiness ? route('business.build') : route('builder') }}" class="flex items-center gap-3">
                <span class="power-pulse flex h-9 w-9 items-center justify-center rounded-xl text-white {{ $isBusiness ? 'bg-blue-600' : 'bg-pctg-primary' }}">
                    {{-- Theme-neutral SVGs from the professional set, verified
                         against components/pctg/icon.blade.php. An unknown icon
                         key renders nothing at all, so these are not guessed. --}}
                    <x-pctg.icon :name="$isBusiness ? 'building' : 'zap'" class="h-5 w-5" />
                </span>
                <span class="leading-tight">
                    <span :class="scrolled ? 'text-base' : 'text-lg'" class="block font-bold transition-all duration-300">
                        {{ $isBusiness ? 'PCTG Business' : 'PCTG Builder' }}
                    </span>
                    <span x-show="!scrolled" x-transition.opacity.duration.200ms class="block text-xs text-slate-500">
                        {{ $isBusiness ? 'Technology That Means Business' : 'Get Your Gamers Edge&trade;' }}
                    </span>
                </span>
            </a>
        </div>

        <nav class="hidden items-center gap-6 text-sm lg:flex">
            @foreach ($links as $key => $link)
                <a
                    href="{{ $link[1] }}"
                    class="{{ $active === $key ? 'text-white' : 'text-slate-400 transition hover:text-white' }}"
                >
                    {{ $link[0] }}
                </a>
            @endforeach
        </nav>

        <div class="flex items-center gap-4">
            <div class="h-9 w-9 rounded-full {{ $isBusiness ? 'bg-blue-500' : 'bg-red-500' }}"></div>
        </div>
    </div>
</header>
