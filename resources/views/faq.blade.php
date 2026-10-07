@extends('layouts.seo')

@section('title')
    Gaming PC Builder FAQ | {{ config('brand.name') }}
@endsection

@section('description')
    Answers to the questions buyers ask before ordering a custom gaming PC: warranty, delivery, UK build, prices in GBP, software and where to start.
@endsection

@php
    $faqs = [
        [
            'q' => 'Do you build custom gaming PCs in the UK?',
            'a' => 'Yes. PCTechGuy Online is a UK custom gaming PC builder based in Bristol. Every build is assembled and tested in the UK, and we deliver to mainland UK and Northern Ireland. You configure your own PC online using our AI-assisted configurator, or choose from our prebuilt range.',
        ],
        [
            'q' => 'Can I configure and buy a custom gaming PC online?',
            'a' => 'Yes. Our online configurator lets you choose the parts and the styling, and it optimises the specification for gaming performance. You can also start from a prebuilt configuration and change components, whichever suits you. Every component is named explicitly on the build so you know exactly what you are buying.',
        ],
        [
            'q' => 'How long does the warranty last and what does it cover?',
            'a' => 'Your system is covered by a return-to-base warranty covering parts and labour. If a fault cannot be fixed remotely or over the phone, you return the system to us, we repair it and arrange return delivery within mainland UK. The warranty covers components inside the system; peripherals such as monitors, keyboards, mice and speakers are covered by their own manufacturer\'s warranty. Alongside the hardware warranty we provide free remote technical support for the lifetime of your system, where we can resolve software, driver and malware issues over a remote connection, provided your PC still boots into Windows.',
        ],
        [
            'q' => 'How much is delivery in the UK?',
            'a' => 'Delivery to mainland UK and Northern Ireland is included in the price. We use Royal Mail Special Delivery with insurance, and deliveries are normally next day before 12pm once dispatched. Dispatch is normally 3 to 5 working days from receipt of parts. Extended UK, Ireland and Europe is available via Parcelforce at additional cost, with a quote on request.',
        ],
        [
            'q' => 'How long does a custom build take to arrive?',
            'a' => 'Allow 3 to 5 working days for dispatch from receipt of parts, then next-day-before-12pm Royal Mail Special Delivery to mainland UK and Northern Ireland. Builds that use a component we need to source take longer, and we will tell you before the order is placed.',
        ],
        [
            'q' => 'Can you repair or upgrade a PC I already own?',
            'a' => 'Yes. Alongside custom builds we provide PC repair, diagnostics, upgrades, game optimisation and remote technical support. We also run a community service, PC Pitstop, which offers free labour for local Bristol residents in the BS9 to BS11 postcodes. Remote support is available to any customer with a PC that still boots into Windows.',
        ],
        [
            'q' => 'Are your prices in GBP and do they include VAT?',
            'a' => 'Yes. All prices are in UK pounds sterling and include VAT. Component prices and stock levels change with supplier pricing, so prices are correct at the time of publishing and we reserve the right to cancel or refuse an order where a price or availability error has occurred.',
        ],
        [
            'q' => 'What do your customers say about the builds?',
            'a' => 'Our customer reviews and testimonials are published on our own site, and our Trustpilot profile is rated Excellent with 100% five-star reviews. We publish reviews on our own domain as well as on Trustpilot so the feedback is verifiable by you before you order.',
        ],
        [
            'q' => 'Can I speak to the person who built my PC?',
            'a' => 'Yes. We are a small UK operation rather than a call centre, and you deal directly with the people who assemble and support the machines. Support is available Monday to Friday, 9:00 to 17:00, by phone on +44 7933 101083 or by email at info@pctechguyonline.com.',
        ],
        [
            'q' => 'Do you sell Windows and other software licences?',
            'a' => 'Yes. We sell genuine Windows, Office, antivirus and utility software, and can install and configure it as part of a build or service. Software is available from our software page on the website.',
        ],
    ];
@endphp

@section('content')

<article>

    <h1 class="text-5xl font-black">
        Gaming PC Builder FAQ
    </h1>

    <p class="mt-6 text-lg text-slate-400">
        The questions buyers actually ask before ordering a custom gaming PC —
        answered in plain English. Everything below reflects what we publish on our
        site on {{ now()->format('jS F Y') }}.
    </p>

    <section class="mt-12 space-y-8">

        @foreach ($faqs as $index => $faq)
            <div class="rounded-2xl border border-slate-800/60 bg-slate-900/40 p-6">
                <h2 class="text-2xl font-bold">
                    {{ $faq['q'] }}
                </h2>
                <p class="mt-4 leading-relaxed text-slate-400">
                    {{ $faq['a'] }}
                </p>
            </div>
        @endforeach

    </section>

    <section class="mt-20">

        <x-pctg.hero>

            <h2 class="text-4xl font-black">
                Ready To Build Your Own PC?
            </h2>

            <p class="mt-6 text-slate-400">
                Use the PCTG AI Builder to generate a compatible, performance-checks
                gaming PC matched to your budget and games.
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

@section('schema')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        @foreach ($faqs as $index => $faq)
        {
            "@type": "Question",
            "name": {!! json_encode($faq['q'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
            "acceptedAnswer": {
                "@type": "Answer",
                "text": {!! json_encode($faq['a'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
            }
        }{{ ! $loop->last ? ',' : '' }}
        @endforeach
    ]
}
</script>
@endsection