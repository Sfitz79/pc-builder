@extends('layouts.seo')

@section('title')
    Prebuilt Gaming PC Under £800 UK | What The Budget Really Delivers
@endsection

@section('description')
    What a prebuilt gaming PC under £800 realistically delivers in the UK in 2026 — an honest spec, live-priced, with no £800 fantasy rig.
@endsection

@php
$faqs = [
    [
        'q' => 'Can I get a good prebuilt gaming PC for under £800 in the UK?',
        'a' => 'For under £800 you can buy a solid 1080P gaming machine, but you have to make the right compromises: a strong value graphics card, a sensible modern CPU and enough fast memory. What you will not get is high-refresh 1440P or ray-tracing performance at that price, and any builder claiming otherwise is not being honest with you.',
    ],
    [
        'q' => 'Is it better to build my own PC than buy a prebuilt under £800?',
        'a' => 'A custom or prebuilt machine from a builder that names every component is usually the safer buy, because you know exactly what is inside and the price is anchored to the live component catalogue. DIY can save a little, but only if you value your own time at zero and accept the risk of handling the parts yourself.',
    ],
    [
        'q' => 'Where does a £800 prebuilt fall short?',
        'a' => 'At under £800 the GPU is the constraint. You are looking at 1080P ultra in many games and high frame rates in esports titles — not 4K, not ray-tracing at high settings, and probably not future-proofed if you only upgrade the CPU later. Those are facts, not a sales pitch.',
    ],
    [
        'q' => 'Are the prices on this page real?',
        'a' => 'Yes. The sample build below is assembled from our live component catalogue at render time, so the parts and total shown are our current prices in GBP including VAT on the day you view the page.',
    ],
];
@endphp

@section('schema')
    @include('schema.guide', [
        'headline' => 'Prebuilt Gaming PC Under £800 UK — What The Budget Really Delivers',
        'description' => 'An honest guide to what a sub-£800 prebuilt gaming PC delivers in the UK, live-priced from the component catalogue.',
        'crumbs' => [
            ['name' => 'Prebuilts', 'url' => url('/prebuilts')],
        ],
        'faqs' => $faqs,
    ])
@endsection

@section('content')

<article>

    <h1 class="text-5xl font-black">
        Prebuilt Gaming PC Under £800 UK
    </h1>

    <p class="mt-6 text-lg text-slate-400">
        A prebuilt gaming PC under £800 in the UK is a 1080P machine, and you should
        be sold it as one. At this price the graphics card is the whole game: a
        value current-gen card will handle esports at high frame rates and most
        titles at 1080P ultra. What it will not do is 4K or high-end ray tracing.
        The sample build below is priced live from our catalogue so you can see a
        real number, not a rounded one.
    </p>

    <div class="mt-10">
        <x-pctg.badge>
            Updated {{ now()->format('jS F Y') }}
        </x-pctg.badge>
    </div>

    <x-pctg.seo-recommended-build
        :budget="800"
        :title="'Recommended £800 Prebuilt Spec (live catalogue price)'"
        :resolution="'1080P'"
    />

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            What Matters Most At This Price
        </h2>

        <p class="mt-6 text-slate-400">
            Under £800, prioritise the graphics card first, then memory and
            storage. Modern games are GPU-bound at this level, so a machine with a
            strong card but a modest CPU will genuinely outperform one that
            overspends on the processor and fits a weak card. Watch for prebuilts
            advertising a big CPU with an unnamed "gaming graphics card" — that is
            the classic way to hide a weak GPU.
        </p>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            The Red Flags In £800 Prebuilt Listings
        </h2>

        <ul class="mt-6 space-y-3 text-slate-400">
            <li>⛔ "Gaming graphics card" — if the GPU model is not named, assume it is the weakest part.</li>
            <li>⛔ "Up to 4K ready" language on a sub-£800 machine.</li>
            <li>⛔ A giant CPU "sale" with a tiny, unnamed PSU.</li>
            <li>✓ Every component named, including motherboard and PSU.</li>
            <li>✓ A price stated in GBP including VAT, dated to today.</li>
        </ul>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            Frequently Asked Questions
        </h2>

        <div class="mt-8 space-y-8">
            @foreach ($faqs as $faq)
                <div>
                    <h3 class="text-xl font-semibold">{{ $faq['q'] }}</h3>
                    <p class="mt-3 text-slate-400">{{ $faq['a'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <x-pctg.seo-pagination
        previous="/best-gaming-pc-under-1000"
        previous-title="Best Gaming PC Under £1000"
        next="/guides/best-value-gaming-pc-builder-uk"
        next-title="Best Value Builder"
    />

    <x-pctg.seo-buyer-guides />

    <x-pctg.seo-budget-links />

    <section class="mt-20">
        <x-pctg.hero>
            <h2 class="text-4xl font-black">
                Configure Your Own £800 Machine
            </h2>
            <p class="mt-6 text-slate-400">
                Set a £800 budget in the AI builder and let it assemble a
                compatible build you are free to adjust part by part.
            </p>
            <div class="mt-8">
                <a href="{{ route('builder') }}" class="pctg-button">
                    Launch AI Builder
                </a>
            </div>
        </x-pctg.hero>
    </section>

</article>

@endsection