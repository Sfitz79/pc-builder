@extends('layouts.seo')

@section('title')
    Custom Gaming PC Under £1000 UK | Real Spec From The Live Catalogue
@endsection

@section('description')
    What a custom gaming PC under £1000 actually buys in the UK in 2026. A named spec with a live, dated price from our catalogue — not a made-up figure.
@endsection

@php
$faqs = [
    [
        'q' => 'Can you build a custom gaming PC for under £1000 in the UK?',
        'a' => 'Yes. At £1000 the sensible focus is a strong 1080P gaming machine: a modern AM5 processor, a capable current-generation graphics card and 16–32GB of DDR5. The sample build on this page is pulled live from our component catalogue with today\'s prices — check the total on the page, not a number in this answer.',
    ],
    [
        'q' => 'Is £1000 enough for 1440P gaming?',
        'a' => 'Not comfortably. A £1000 build is best aimed at 1080P. For solid 1440P you want to be closer to £1500, which buys a materially stronger GPU. The £1000 page stays honest about that limit.',
    ],
    [
        'q' => 'Where should the money go in a £1000 gaming PC?',
        'a' => 'The graphics card should take the largest single share — roughly 40% of the build — followed by the CPU. Skimping on the GPU to spend on looks or lighting is the most common budget mistake.',
    ],
    [
        'q' => 'Are the prices on this page real and current?',
        'a' => 'Yes. The sample build on this page is assembled from our live component catalogue at render time, so the parts and total shown are our current prices in GBP including VAT on the day you view the page.',
    ],
];
@endphp

@section('schema')
    @include('schema.guide', [
        'headline' => 'Custom Gaming PC Under £1000 UK — Real Spec From The Live Catalogue',
        'description' => 'A real £1000 custom gaming PC spec, priced live from the PCTechGuy component catalogue.',
        'crumbs' => [
            ['name' => 'Best Gaming PC Under £1000', 'url' => url('/best-gaming-pc-under-1000')],
        ],
        'faqs' => $faqs,
    ])
@endsection

@section('content')

<article>

    <h1 class="text-5xl font-black">
        Custom Gaming PC Under £1000 UK
    </h1>

    <p class="mt-6 text-lg text-slate-400">
        A custom gaming PC under £1000 in the UK should be a 1080P machine: a modern
        AM5 processor, a current-generation graphics card and fast DDR5 memory.
        Pushing for 1440P at this budget means cutting corners you will feel later.
        The build on this page is pulled from our live catalogue, so the parts and
        the total are real and dated — not a number someone typed from memory.
    </p>

    <div class="mt-10">
        <x-pctg.badge>
            Updated {{ now()->format('jS F Y') }}
        </x-pctg.badge>
    </div>

    <x-pctg.seo-recommended-build
        :budget="1000"
        :title="'Recommended £1000 Custom Gaming PC (live catalogue price)'"
        :resolution="'1080P'"
    />

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            How To Spend £1000 Without Wasting It
        </h2>

        <ul class="mt-6 space-y-3 text-slate-400">
            <li>✓ Graphics card first — the single biggest factor in gaming performance at this budget.</li>
            <li>✓ A modern AM5 platform so later CPU upgrades are possible without changing the board.</li>
            <li>✓ 16GB DDR5 minimum; 32GB if the budget stretches.</li>
            <li>✓ A Gen4 NVMe drive — storage is cheap and the load-time difference is real.</li>
            <li>✓ A quality PSU with headroom. Do not save three pounds on the part that powers the whole machine.</li>
        </ul>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            The Honest Limit At £1000
        </h2>

        <p class="mt-6 text-slate-400">
            At £1000 you are buying excellent 1080P performance and a platform you
            can upgrade later — not a 4K machine. That is the honest boundary, and
            any builder or article that claims more for the money is selling you
            something. If 1440P is the goal, our
            <a href="{{ url('/best-gaming-pc-under-1500') }}" class="text-red-400 hover:text-red-300">
                £1500 guide
            </a>
            shows what the extra budget buys.
        </p>
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
        next="/guides/gaming-pc-900-1440p-uk"
        next-title="£900 Gaming PC for 1440P"
    />

    <x-pctg.seo-buyer-guides />

    <x-pctg.seo-budget-links />

    <section class="mt-20">
        <x-pctg.hero>
            <h2 class="text-4xl font-black">
                Build A £1000 Rig With AI
            </h2>
            <p class="mt-6 text-slate-400">
                Set your budget to £1000 in the AI builder and it will assemble a
                compatible, performance-checked build — you stay in control of every part.
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