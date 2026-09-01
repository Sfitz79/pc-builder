@extends('layouts.seo')

@section('title', 'Software Store — Windows, Office & More | PCTG Builder')

@section('description', 'Buy genuine software license keys — Windows 11, Microsoft Office, security suites and more. Delivered instantly to your screen after payment.')

@section('content')
    <div x-data="{ showOverlay: true }" class="mx-auto max-w-6xl">
        <div
            x-show="showOverlay"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="test-phase-title"
        >
            <div class="absolute inset-0 bg-black/75" x-on:click="showOverlay = false"></div>

            <div class="relative w-full max-w-md rounded-2xl border border-amber-500/30 bg-pctg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-4">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-500/10">
                        <x-pctg.icon name="alert-triangle" class="h-6 w-6 text-amber-400" />
                    </span>
                    <button
                        type="button"
                        x-on:click="showOverlay = false"
                        class="rounded-lg p-1 text-pctg-text-secondary transition hover:text-white"
                        aria-label="Close notice"
                    >
                        <x-pctg.icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                <h2 id="test-phase-title" class="mt-4 font-display text-xl font-bold text-white">Test Phase — Orders by Request</h2>

                <p class="mt-2 text-sm text-pctg-text-secondary">
                    The App Store is currently in <span class="font-semibold text-amber-300">test phase</span> and
                    <span class="font-semibold text-white">not available to buy directly right now</span>.
                </p>
                <p class="mt-2 text-sm text-pctg-text-secondary">
                    Want to order? Message us on WhatsApp and we'll sort it out for you.
                </p>

                <div class="mt-5 flex flex-col gap-2">
                    <a
                        href="https://wa.me/447933101083"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#25D366] px-4 py-2.5 text-sm font-semibold text-black transition hover:brightness-110"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 448 512" fill="currentColor" aria-hidden="true">
                            <path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/>
                        </svg>
                        Message us on WhatsApp
                    </a>
                    <button
                        type="button"
                        x-on:click="showOverlay = false"
                        class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-pctg-text-secondary transition hover:border-white/25 hover:text-white"
                    >
                        Continue to browse
                    </button>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-pctg-primary-hover">App Store</p>
                <h1 class="mt-2 font-display text-3xl font-bold text-white sm:text-4xl">Software, keys in hand.</h1>
                <p class="mt-3 max-w-2xl text-pctg-text-secondary">
                    Genuine license keys delivered through the Metenzi fulfilment network. Pay securely with PayPal —
                    your key appears on screen the moment payment clears.
                </p>
            </div>
            <div class="rounded-xl border border-white/5 bg-pctg-surface px-4 py-3">
                <p class="flex items-center gap-2 text-sm text-pctg-text-secondary">
                    <x-pctg.icon name="shield-check" class="h-4 w-4 text-pctg-success" />
                    Instant delivery &middot; 30-day warranty
                </p>
            </div>
        </div>

        @if (! $configured)
            <div class="mt-10 rounded-2xl border border-amber-500/20 bg-amber-500/5 p-6">
                <p class="flex items-center gap-3 font-medium text-amber-300">
                    <x-pctg.icon name="alert-triangle" class="h-5 w-5" />
                    The software store is not configured yet.
                </p>
                <p class="mt-2 text-sm text-pctg-text-secondary">
                    Purchases will be enabled once the store credentials are set up. Check back soon.
                </p>
            </div>
        @elseif ($products->isEmpty())
            <div class="mt-10 rounded-2xl border border-white/5 bg-pctg-surface p-6">
                <p class="text-sm text-pctg-text-secondary">No products are in stock right now.</p>
            </div>
        @else
            <div class="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($products as $product)
                    <article
                        class="group flex flex-col rounded-2xl border border-white/5 bg-pctg-surface p-6 transition hover:border-white/15"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span class="inline-flex items-center gap-2 rounded-lg bg-white/5 px-2.5 py-1 text-xs font-medium text-pctg-text-secondary">
                                <x-pctg.icon name="layers" class="h-3.5 w-3.5" />
                                {{ $product->category ?? 'Software' }}
                            </span>
                            @if ($product->warranty_days)
                                <span class="inline-flex items-center gap-1.5 text-xs text-pctg-text-secondary">
                                    <x-pctg.icon name="shield-check" class="h-3.5 w-3.5 text-pctg-success" />
                                    {{ $product->warranty_days }}-day warranty
                                </span>
                            @endif
                        </div>

                        <h2 class="mt-4 font-display text-lg font-bold leading-snug text-white">{{ $product->name }}</h2>

                        @if ($product->short_description)
                            <p class="mt-2 line-clamp-3 text-sm text-pctg-text-secondary">{{ $product->short_description }}</p>
                        @endif

                        <div class="mt-5 flex items-end justify-between gap-4">
                            <div>
                                <p class="text-xs text-pctg-text-secondary">
                                    @if ($product->retail_price > 0)
                                        &euro;{{ number_format((float) $product->retail_price, 2) }} / ~&pound;{{ number_format((float) $product->gbp_price, 2) }}
                                    @else
                                        Price on request
                                    @endif
                                </p>
                                @if ($product->stock < 5 && $product->stock > 0)
                                    <p class="mt-0.5 text-xs font-medium text-amber-300">Only {{ $product->stock }} left</p>
                                @endif
                            </div>

                            <div class="flex flex-col items-end gap-2">
                                <a
                                    href="https://wa.me/447933101083?text={{ urlencode('Hi! I\'d like to order: ' . $product->name) }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-2 rounded-xl bg-[#25D366] px-4 py-2 text-sm font-semibold text-black transition hover:brightness-110"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 448 512" fill="currentColor" aria-hidden="true">
                                        <path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/>
                                    </svg>
                                    Order on WhatsApp
                                </a>
                                <p class="text-[11px] font-medium text-amber-300">Test phase — no direct purchase yet</p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-12 rounded-2xl border border-white/5 bg-pctg-surface p-6">
                <h3 class="flex items-center gap-2 font-display text-sm font-bold uppercase tracking-[0.14em] text-white">
                    <x-pctg.icon name="info" class="h-4 w-4 text-pctg-primary-hover" />
                    How key delivery works
                </h3>
                <ol class="mt-4 grid grid-cols-1 gap-4 text-sm text-pctg-text-secondary sm:grid-cols-3">
                    <li class="flex gap-3"><span class="font-bold text-white">1.</span> Buy with PayPal — you are charged in GBP.</li>
                    <li class="flex gap-3"><span class="font-bold text-white">2.</span> The key is ordered from our fulfilment partner instantly.</li>
                    <li class="flex gap-3"><span class="font-bold text-white">3.</span> Your key appears on the confirmation screen with a copy button.</li>
                </ol>
                <p class="mt-4 text-xs text-pctg-text-secondary">
                    Prices shown in EUR are the supplier's retail list price; the GBP figure is the amount charged at checkout.
                    Software is delivered digitally — nothing is shipped.
                </p>
            </div>
        @endif
    </div>
@endsection
