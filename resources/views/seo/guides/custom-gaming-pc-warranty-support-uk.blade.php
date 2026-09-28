@extends('layouts.seo')

@section('title')
    Custom Gaming PC Warranty & Support UK | Our Terms In Plain English
@endsection

@section('description')
    Our warranty and support terms for UK custom gaming PCs, published plainly: return-to-base parts and labour cover, plus free remote technical support for the lifetime of your system.
@endsection

@php
$faqs = [
    [
        'q' => 'What warranty comes with a custom gaming PC?',
        'a' => 'Your system is covered by a return-to-base warranty covering parts and labour on the components inside the system. If a fault cannot be fixed remotely or over the phone, you return the system to us, we repair it and arrange return delivery within mainland UK. Peripherals such as monitors, keyboards, mice and speakers are covered by their own manufacturer\'s warranty.',
    ],
    [
        'q' => 'Does the warranty cover labor and delivery?',
        'a' => 'Yes — parts and labour are both covered under the return-to-base warranty, and return delivery within mainland UK is arranged by us as part of the repair process. You do not pay a labour fee for a covered fault.',
    ],
    [
        'q' => 'Is remote support included?',
        'a' => 'Yes. We provide free remote technical support for the lifetime of your system, resolving software, driver and malware issues over a remote connection — provided your PC still boots into Windows.',
    ],
    [
        'q' => 'How do I contact support?',
        'a' => 'By phone on +44 7933 101083 or by email at info@pctechguyonline.com, Monday to Friday 09:00–17:00. Because we are a small operation, you deal directly with the people who assemble and support the machines.',
    ],
];
@endphp

@section('schema')
    @include('schema.guide', [
        'headline' => 'Custom Gaming PC Warranty & Support UK — Our Terms In Plain English',
        'description' => 'PCTechGuy Online warranty and support terms for custom gaming PCs: return-to-base parts and labour, lifetime free remote support.',
        'crumbs' => [
            ['name' => 'Support', 'url' => url('/support')],
        ],
        'faqs' => $faqs,
    ])
@endsection

@section('content')

<article>

    <h1 class="text-5xl font-black">
        Custom Gaming PC Warranty &amp; Support UK
    </h1>

    <p class="mt-6 text-lg text-slate-400">
        Your custom gaming PC is covered by a return-to-base warranty on parts and
        labour, plus free remote technical support for the lifetime of your system.
        We publish these terms plainly because a warranty you cannot read quickly is
        a warranty you cannot rely on. No small print, no "contact us to find out".
    </p>

    <div class="mt-10">
        <x-pctg.badge>
            Updated {{ now()->format('jS F Y') }}
        </x-pctg.badge>
    </div>

    <section class="mt-12">
        <h2 class="text-3xl font-bold">
            Hardware Warranty — Parts &amp; Labour
        </h2>

        <p class="mt-6 text-slate-400">
            The warranty covers the components inside your system. If a fault cannot
            be fixed remotely or over the phone, you return the system to us. We
            repair it and arrange return delivery within mainland UK. Peripherals
            (monitors, keyboards, mice, speakers) are covered by their own
            manufacturer's warranty.
        </p>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            Free Remote Support For Life
        </h2>

        <p class="mt-6 text-slate-400">
            Alongside the hardware warranty, we provide free remote technical
            support for the lifetime of your system — software, driver and malware
            issues resolved over a remote connection, provided your PC still boots
            into Windows. That is not a timed support package; it is the lifetime of
            the machine.
        </p>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            Support Hours + Contact
        </h2>

        <div class="mt-6 space-y-3 text-slate-400">
            <p><strong class="text-white">Phone:</strong> +44 7933 101083</p>
            <p><strong class="text-white">Email:</strong> info@pctechguyonline.com</p>
            <p><strong class="text-white">Hours:</strong> Monday to Friday, 09:00–17:00 (closed weekends)</p>
            <p><strong class="text-white">What a support call looks like:</strong> directly with the people who build and support the machines — not a call centre script.</p>
        </div>
    </section>

    <section class="mt-16">
        <h2 class="text-3xl font-bold">
            What To Ask Any Builder Before You Hand Over Money
        </h2>

        <ul class="mt-6 space-y-3 text-slate-400">
            <li>✓ Warranty in writing — is it parts, labour, or both?</li>
            <li>✓ Who arranges return delivery in the event of a fault?</li>
            <li>✓ Is remote support bundled, timed, or paid per incident?</li>
            <li>✓ Where is the machine assembled and tested?</li>
            <li>✓ Are every component names published before I pay?</li>
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
        previous="/guides/best-value-gaming-pc-builder-uk"
        previous-title="Best Value Builder"
        next="/guides/best-uk-custom-gaming-pc-builder"
        next-title="Best UK Custom Builder"
    />

    <x-pctg.seo-buyer-guides />

    <section class="mt-20">
        <x-pctg.hero>
            <h2 class="text-4xl font-black">
                Start A Build, Covered End To End
            </h2>
            <p class="mt-6 text-slate-400">
                Configure a machine in the AI builder with the warranty and support
                terms above attached to it.
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