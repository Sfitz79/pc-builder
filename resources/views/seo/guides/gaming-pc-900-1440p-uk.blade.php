@extends('layouts.seo')

@section('title')
    £900 Gaming PC 1440P UK | What The Budget Actually Buys
@endsection

@section('description')
    Can £900 buy a 1440P gaming PC in the UK in 2026? The honest answer, a live-priced £900 recommended build, and where the budget genuinely runs out.
@endsection

@php
$faqs = [
    [
        'q' => 'Can a £900 gaming PC handle 1440P?',
        'a' => 'Partially, and honestly. At £900 you can play many titles at 1440P, especially with upscaling (DLSS/FSR), but you will be switching settings down in demanding AAA games. A comfortable 1440P machine — high settings, high frame rates — realistically starts around £1500. The £900 build below is priced live so you can see exactly what the money buys.',
    ],
    [
        'q' => 'What should I spend on if I must game at 1440P on a £900 budget?',
        'a' => 'The graphics card. At this budget the GPU is the bottleneck at 1440P, so maximise it and accept a modest but modern CPU. Use upscaling in demanding titles — DLSS and FSR are precisely what makes 1440P work on tighter budgets.',
    ],
    [
        'q' => 'Is 1440P at £900 going to feel smooth?',
        'a' => 'In esports titles, yes — they are light and will run high frame rates at 1440P. In AAA single-player games you will be at 1440P with medium-to-high settings and relying on upscaling. If you wanted to upgrade later, the platform choice matters more than anything else.',
    ],
    [
        'q' => 'Are these prices real?',
        'a' => 'Yes. The recommended build is assembled from our live component catalogue at render time, so the parts and total are our current prices in GBP including VAT on the day you view the page.',
    ],
];
@endphp

@section('schema')
    @include('schema.guide', [
        'headline' => '£900 Gaming PC 1440P UK — What The Budget Actually Buys',
        'description' => 'The honest answer on whether £900 buys a 1440P gaming PC in the UK, with a live-priced recommended build.',
        'crumbs' => [
            ['name' => 'Best Gaming PC Under £1000', 'url' => url('/best-gaming-pc-under-1000')],
        ],
        'faqs' => $faqs,
    ])
@endsection

@section('content')

<article>

    <h1 class="text-5xl font-black">
        £900 Gaming PC 1440P UK
    </h1>

    <p class="mt-6 text-lg text-slate-400">
        A £900 gaming PC can do 1440P in the UK in 2026 — with honest caveats. You
        will run esports titles at high frame rates and many single-player games
        at 1440P with upscaling, but demanding AAA titles will need settings
        dialled back and you will rely on DLSS or FSR. A genuinely comfortable
        1440P machine starts nearer £1500. The build below is priced live from our
        catalogue so the numbers are real, dated and verifiable.
    </p>

    <div class="mt-10">
        <x-pctg.badge>
            Updated {{ now()->format('jS F Y') }}
        </x-pctg.badge>
    </div>

    <x-pctg.seo-recommended-build
        :budget="900"
        :title="'Recommended £900 1440P Gaming PC (live catalogue price)'"
        :resolution="'1440P'"
    />

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            The £900 Budget Strategy For 1440P
        </h2>

        <p class="mt-6 text-slate-400">
            If 1440P is the goal at £900, the graphics card gets the largest share
            of the budget — then the platform. A modern motherboard and CPU with a
            real upgrade path means your later GPU upgrade actually helps. Spend a
            small amount on the case, cooling and storage and put everything else
            into the card.
        </p>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            Where The Budget Runs Out
        </h2>

        <ul class="mt-6 space-y-3 text-slate-400">
            <li>⛔ Ray tracing — forget it at the high end on this budget.</li>
            <li>⛔ 1440P ultra with high frame rates in demanding AAA titles.</li>
            <li>⛔ A big CPU upgrade down the line without a platform that supports it.</li>
            <li>✓ Esports at high frame rates, 1440P.</li>
            <li>✓ Single-player titles at 1440P medium-to-high with upscaling.</li>
        </ul>

        <p class="mt-6 text-slate-400">
            If that honest split does not match what you want from 1440P, the
            realistic alternative is stepping up a tier — our
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
        previous="/guides/custom-gaming-pc-under-1000-uk"
        previous-title="Custom Gaming PC Under £1000"
        next="/best-gaming-pc-under-1500"
        next-title="Best Gaming PC Under £1500"
    />

    <x-pctg.seo-buyer-guides />

    <x-pctg.seo-budget-links />

    <section class="mt-20">
        <x-pctg.hero>
            <h2 class="text-4xl font-black">
                Set Your Own 1440P Budget
            </h2>
            <p class="mt-6 text-slate-400">
                Tell the AI builder your target resolution and budget and it will
                assemble a compatible build — decide for yourself where the trade-offs land.
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