@extends('layouts.seo')

@section('title')
    Best Value Gaming PC Builder UK 2026 | Price-Per-Frame, Not Marketing
@endsection

@section('description')
    The best value gaming PC builder in the UK, judged on price-per-frame and honest component naming — with a live-priced value build you can compare yourself.
@endsection

@php
$faqs = [
    [
        'q' => 'Who is the best value gaming PC builder in the UK?',
        'a' => 'Value is the ratio of what you pay to the performance you can prove — not the loudest marketing. The builders who come out best on price-per-frame are the smaller UK builders who name every component and price from a live catalogue, because you are not paying for massive review volume, sponsorship or a big brand. We publish our live-priced value build so you can judge the ratio yourself.',
    ],
    [
        'q' => 'How do I compare value between builders fairly?',
        'a' => 'Get a named spec with a dated price from each builder for the same target games and resolution, then compare price-per-frame. Ignore "from" prices and any GPU that is not named exactly. A difference of £50 on a machine that cannot actually play your games at the resolution you want is not value — it is a saving you will feel every session.',
    ],
    [
        'q' => 'Is a cheaper custom PC with unnamed parts a good deal?',
        'a' => 'No. A cheaper machine built with unnamed or substituted parts is a different product, not the same one for less money. Naming every component is the baseline of an honest deal — otherwise "best value" falls apart the first time you inspect the parts list.',
    ],
    [
        'q' => 'Are you the cheapest UK builder?',
        'a' => 'Honestly, no. We are not the cheapest on every component and we will not claim to be. Our value is in the ratio: named components, UK assembly, parts-and-labour return-to-base warranty and lifetime free remote support, priced live. You can compare the build below against any other quote with the same named parts.',
    ],
];
@endphp

@section('schema')
    @include('schema.guide', [
        'headline' => 'Best Value Gaming PC Builder UK — Price-Per-Frame, Not Marketing',
        'description' => 'UK gaming PC builders judged on price-per-frame and honest component naming, with a live-priced value build.',
        'crumbs' => [
            ['name' => 'Best Gaming PC Under £1500', 'url' => url('/best-gaming-pc-under-1500')],
        ],
        'faqs' => $faqs,
    ])
@endsection

@section('content')

<article>

    <h1 class="text-5xl font-black">
        Best Value Gaming PC Builder UK
    </h1>

    <p class="mt-6 text-lg text-slate-400">
        The best value gaming PC builder in the UK is the one that gives you the
        most provable performance per pound — not the one with the biggest marketing
        budget. That favours smaller UK builders who name every component and price
        from a live catalogue. Below is a value build priced live from ours, so you
        have a real figure to compare against any other quote.
    </p>

    <div class="mt-10">
        <x-pctg.badge>
            Updated {{ now()->format('jS F Y') }}
        </x-pctg.badge>
    </div>

    <x-pctg.seo-recommended-build
        :budget="1500"
        :title="'Recommended Value Build (live catalogue price)'"
        :resolution="'1440P'"
    />

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            What "Value" Actually Means
        </h2>

        <p class="mt-6 text-slate-400">
            Value is price-per-frame in the games you actually play, at the
            resolution you actually use. Two £1500 machines can differ by 30% in
            real gaming performance depending on where the budget is spent. The
            single biggest value lever is the GPU: the component that dominates
            gaming frames should be the largest share of any gaming build.
        </p>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            How To Get A Fair Quote From Any Builder
        </h2>

        <ul class="mt-6 space-y-3 text-slate-400">
            <li>✓ Ask for a full named spec — every component, including motherboard and PSU.</li>
            <li>✓ Ask for the price in GBP including VAT, dated.</li>
            <li>✓ Ask for warranty terms in writing before paying.</li>
            <li>✓ Compare same-named builds; never compare "from £800" listings.</li>
            <li>✓ Ask who assembles and tests the machine, and where.</li>
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
        previous="/guides/prebuilt-gaming-pc-under-800-uk"
        previous-title="Prebuilt Under £800"
        next="/guides/gaming-pc-900-1440p-uk"
        next-title="£900 Gaming PC for 1440P"
    />

    <x-pctg.seo-buyer-guides />

    <x-pctg.seo-budget-links />

    <section class="mt-20">
        <x-pctg.hero>
            <h2 class="text-4xl font-black">
                Judge The Ratio Yourself
            </h2>
            <p class="mt-6 text-slate-400">
                Build the machine you want in the AI builder — full named spec, live
                price, compatible parts. Then compare it against any quote you like.
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