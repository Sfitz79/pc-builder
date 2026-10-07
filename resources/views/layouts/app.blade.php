@props([
    'title' => null,
    'description' => 'PC Builder — configure a custom gaming PC to your own spec, assembled and tested in the UK. UK delivery included in the price, two-year warranty, GBP prices that include VAT.',
    /*
     * `chrome` renders the MARKETING furniture around the slot: the public
     * navigation-menu, the max-width centred container and the site footer.
     *
     * The app shell turns it OFF. Measured 2026-10-07 on https://pctechguy.app/builder:
     * the builder layout nests inside this one and then renders its own header plus a
     * `fixed` sidebar with `md:ml-72` offsets, all sized for a full-bleed viewport
     * shell. Wrapping that in the marketing nav + `max-w-[1600px] px-4 py-6`
     * container pushed everything down while the fixed sidebar stayed pinned to the
     * viewport, so the category list was clipped and two headers stacked. The app
     * shell draws its own header and its own footer bar; it must not inherit the
     * marketing one.
     *
     * `livewire` loads Livewire - and therefore Livewire's OWN copy of Alpine.
     *
     * The builder turns it OFF. Measured on the live page: with Livewire's
     * script loading, resources/js/app.js's Alpine and Livewire's Alpine both
     * run, and the browser warns "Detected multiple instances of Alpine
     * running". No view under resources/views/builder/** uses a Livewire
     * component at all (verified by grep), so the second copy bought nothing.
     *
     * IMPORTANT — THE SYMPTOMS THAT USED TO BE BLAMED ON THIS WERE MISATTRIBUTED.
     * The errors below were long recorded here as proof of the dual-Alpine
     * problem:
     *
     *   Alpine Expression Error: componentModal is not defined
     *   Alpine Expression Error: categoryLabel is not defined
     *   Alpine Expression Error: search is not defined
     *   Alpine Expression Error: filteredComponents is not defined   (x2)
     *
     * That was wrong. Measured in the browser: with livewire=false already in
     * force, only ONE script tag loads and window.Livewire is undefined, yet
     * all four errors persist. Calling builderState() directly returns a
     * healthy 83-key object containing all four members. The real cause was
     * Alpine SCOPE, not duplication — the state was declared on a wrapper
     * inside builder/dashboard.blade.php while the builder layout rendered
     * its header, sidebar, mobile drawer and checkout footer as siblings of
     * that wrapper, outside the only scope that owned the state. Fixed by
     * hoisting x-data="builderState()" to components/pctg/layouts/builder.
     * blade.php. Do not "re-fix" this by toggling Livewire.
     *
     * Worth being precise about what that did and did not break: nothing here
     * was a styling failure and nothing was a missing stylesheet. The page
     * rendered; four Alpine expressions simply could not evaluate.
     *
     * Both default to true, so every other page is byte-for-byte unchanged.
     */
    'chrome' => true,
    'livewire' => true,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="no-js dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>document.documentElement.classList.remove('no-js');</script>

    <title>{{ ($title ? $title . ' | ' : '') . config('brand.name') }}</title>

    <meta name="description" content="{{ $description }}">

    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('brand.name') }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta property="og:title" content="{{ ($title ? $title . ' | ' : '') . config('brand.name') }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:locale" content="en_GB">
    {{-- og:image was MISSING while twitter:card declared summary_large_image,
         so shared links rendered as bare text cards. See layouts/seo.blade.php
         for the full note. --}}
    <meta property="og:image" content="{{ asset('img/brand/pctg-og.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="PCTechGuy Online — custom gaming PCs built and tested in the UK">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ ($title ? $title . ' | ' : '') . config('brand.name') }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ asset('img/brand/pctg-og.png') }}">

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/brand/pctg-mark.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/brand/pctg-mark-180.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @include('partials.schema-localbusiness')

    @stack('schema')

    @if($livewire) @livewireStyles @endif
    @stack('styles')
</head>
<body class="min-h-screen bg-pctg-background font-sans text-pctg-text-primary">
    @if($chrome)
        @include('navigation-menu')

        <div class="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>

        <footer class="border-t border-white/5 bg-pctg-surface">
            <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-4 px-4 py-6 sm:px-6 lg:px-8">
                <p class="text-sm text-pctg-text-secondary">&copy; {{ date('Y') }} {{ config('brand.name') }}. {{ config('brand.tagline') }}&trade;</p>
                <div class="flex items-center gap-6 text-sm text-pctg-text-secondary">
                    <a href="{{ route('privacy') }}" class="transition hover:text-white">Privacy</a>
                    <a href="{{ route('terms') }}" class="transition hover:text-white">Terms</a>
                    <a href="{{ route('support') }}" class="transition hover:text-white">Contact</a>
                </div>
            </div>
        </footer>
    @else
        {{-- Full-bleed app shell: the layout draws its own header, sidebar and
             footer bar, so no marketing nav and no width constraint here. --}}
        {{ $slot }}
    @endif

    @if($livewire) @livewireScripts @endif
    @stack('scripts')
</body>
</html>
