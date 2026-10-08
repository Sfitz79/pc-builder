@extends('layouts.seo')

@section('title', 'Business Systems, Studio Workstations & Servers | ' . config('brand.name') . ' Business')

@section('description', 'Professional workstation PCs, studio editing and render systems, and enterprise servers built to order in the UK. PCTG Business — technology that means business.')

@section('content')

{{-- ===================================================================
     PCTG BUSINESS — the professional sub-brand.

     SCOPE OF THE RESTRICTION IN THIS FILE, and it is deliberate:
     red is never used here. The Business pages ask for the `biz-*` Tailwind
     tokens (royal blue) rather than the `pctg-*` ones, because Tailwind
     compiles those to literal colour values at build time - the shared
     x-pctg.* components hardcode `pctg-primary` classes and would render red
     on this page no matter what wrapper class was applied. So the markup
     below is written against `biz-*` directly and uses x-pctg.icon only,
     which is theme-neutral SVG.

     AESTHETIC: the supplied sub-brand mark is chrome/silver with a blue
     accent, so this page leans cool - slate and blue, no red at all.

     ON CLAIMS: this page deliberately states NO benchmark figures, NO prices
     and NO stock. It describes what the systems are for and routes to the
     builder or to contact. Business buyers ask for quoted configurations and
     inventing a spec or a price here would be the same failure as the
     GBP 1,096 headline, only in a more expensive context.
     =================================================================== --}}

<div class="bg-biz-background text-biz-text-primary">

    {{-- ---------------------------------------------------------------
         HERO
         --------------------------------------------------------------- --}}
    <section class="relative overflow-hidden border-b border-white/5">
        <div class="absolute right-0 top-0 h-96 w-96 rounded-full bg-blue-700/20 blur-[140px]" aria-hidden="true"></div>

        <div class="relative mx-auto grid max-w-[1600px] items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:px-8 lg:py-24">
            <div>
                <img
                    src="{{ asset('img/business/logo.png') }}"
                    alt="PCTechGuy Business — Technology That Means Business"
                    width="1024"
                    height="1024"
                    loading="eager"
                    fetchpriority="high"
                    decoding="async"
                    class="w-full max-w-sm"
                >

                <h1 class="mt-8 text-4xl font-black leading-tight md:text-6xl">
                    Business systems
                    <span class="block text-biz-primary">built to order in the UK</span>
                </h1>

                <p class="mt-6 max-w-xl text-lg text-biz-text-secondary">
                    Workstations, studio and render systems, and enterprise servers. Built
                    around your software and your workload — not around a spec sheet — then
                    assembled, tested and covered by a two-year warranty.
                </p>

                <div class="mt-10 flex flex-wrap gap-4">
                    <a href="{{ route('builder') }}"
                       class="inline-flex items-center gap-2 rounded-2xl bg-biz-primary px-6 py-3 font-bold text-white shadow-glow-biz transition hover:bg-biz-primary-hover">
                        Start a build
                    </a>
                    <a href="{{ route('support') }}"
                       class="inline-flex items-center gap-2 rounded-2xl border border-biz-primary/40 bg-white/5 px-6 py-3 font-bold text-white transition hover:border-biz-primary hover:bg-white/10">
                        Talk to us
                    </a>
                </div>
            </div>

            <div class="relative">
                {{-- Supplied enterprise artwork: rack server, workstation, laptops,
                     monitoring dashboards, network storage.
                     GENERATED ARTWORK, so it is captioned as illustrative rather
                     than presented as a photograph of equipment we installed. --}}
                <figure>
                    <img
                        src="{{ asset('img/business/enterprise.jpg') }}"
                        alt="An enterprise setup: a rack-mounted server beside a workstation and laptops, with monitoring dashboards on screen and network storage alongside."
                        width="1500"
                        height="1000"
                        loading="eager"
                        fetchpriority="high"
                        decoding="async"
                        class="w-full rounded-3xl border border-white/10 shadow-panel"
                    >
                    <figcaption class="mt-3 text-center text-xs text-biz-text-secondary">
                        Illustrative image
                    </figcaption>
                </figure>
            </div>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         WHO IT IS FOR
         --------------------------------------------------------------- --}}
    <section class="mx-auto max-w-[1600px] px-4 py-16 sm:px-6 lg:px-8">
        <h2 class="text-3xl font-black md:text-4xl">Who these are for</h2>
        <p class="mt-4 max-w-3xl text-biz-text-secondary">
            Same build discipline as our gaming systems, aimed at work that has to finish.
        </p>

        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Icon names verified against components/pctg/icon.blade.php. The
     professional set (film, monitor, server, database, network,
     hard-drive-nas, briefcase, building, palette, renders, stream) was ADDED
     for this sub-brand rather than approximated with a generic glyph,
     because the component renders NOTHING for an unknown key - a wrong name
     is a silent hole, not an error. --}}
            @foreach ([
                ['icon' => 'film',         'title' => 'Creative & Studio',   'body' => 'Editing, colour, VFX and animation. Timeline playback and render times are the constraint, so the GPU and memory come first.'],
                ['icon' => 'renders',      'title' => 'Pro Rendering',      'body' => 'Frames, simulations and final output. Sustained all-core load and cooling matter more than peak boost.'],
                ['icon' => 'server',       'title' => 'Enterprise Servers', 'body' => 'On-site compute, virtualisation and shared storage. Built for serviceability and quiet operation in a room.'],
                ['icon' => 'hard-drive-nas','title' => 'NAS & Storage',     'body' => 'Network-attached storage and backup for studios and small teams. Drive bays, throughput and RAID come first.'],
                ['icon' => 'stream',       'title' => 'Streaming',          'body' => 'Multi-stream encoding and live production. Sustained encode load and clean output matter more than frame rate.'],
                ['icon' => 'briefcase',    'title' => 'Home Business',      'body' => 'A quiet office machine that does real work quietly. Serviceable, upgradable, and not a gaming build with a business sticker.'],
                ['icon' => 'database',     'title' => 'Data & AI',          'body' => 'Local models, inference and large datasets. Memory capacity and storage throughput set the ceiling.'],
                ['icon' => 'building',     'title' => 'Starter Office',     'body' => 'A dependable first machine for a growing team: shared office workloads, comfortable thermals, straightforward support.'],
            ] as $pillar)
                <div class="rounded-2xl border border-white/5 bg-biz-surface p-6 transition hover:border-biz-primary/40">
                    <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-biz-primary/10 text-biz-accent ring-1 ring-biz-primary/30">
                        <x-pctg.icon :name="$pillar['icon']" class="h-5 w-5" />
                    </div>
                    <h3 class="text-lg font-bold">{{ $pillar['title'] }}</h3>
                    <p class="mt-3 text-sm leading-relaxed text-biz-text-secondary">{{ $pillar['body'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         THE RANGE — using the supplied product artwork
         --------------------------------------------------------------- --}}
    <section class="border-y border-white/5 bg-biz-surface/40">
        <div class="mx-auto max-w-[1600px] px-4 py-16 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div>
                    <h2 class="text-3xl font-black md:text-4xl">The range</h2>
                    <p class="mt-4 max-w-2xl text-biz-text-secondary">
                        Three starting points. Every one is specified with you rather than
                        picked from a shelf.
                    </p>
                </div>
                <img
                    src="{{ asset('img/business/workstation-series.png') }}"
                    alt="PCTG Workstation Series"
                    width="640"
                    height="108"
                    loading="lazy"
                    decoding="async"
                    class="h-auto w-56 opacity-90"
                >
            </div>

            <div class="mt-10 grid gap-6 lg:grid-cols-2">

                {{-- Supplied: dual Radeon Pro W7800, Xeon, multi-GPU. --}}
                <article class="overflow-hidden rounded-3xl border border-white/5 bg-biz-surface">
                    <div class="flex items-center justify-center bg-white p-4">
                        <img
                            src="{{ asset('img/business/server-xeon.jpg') }}"
                            alt="A rack-format server workstation with two Radeon Pro graphics cards, a CPU cooler and multiple hot-swap drive bays."
                            width="733"
                            height="1100"
                            loading="lazy"
                            decoding="async"
                            class="max-h-[26rem] w-auto object-contain"
                        >
                    </div>
                    <div class="p-7">
                        <span class="inline-block rounded-full bg-biz-primary/10 px-3 py-1 text-xs font-bold uppercase tracking-widest text-biz-accent ring-1 ring-biz-primary/30">
                            Server Workstation
                        </span>
                        <h3 class="mt-4 text-2xl font-black">Multi-GPU compute nodes</h3>
                        <p class="mt-3 text-sm leading-relaxed text-biz-text-secondary">
                            Dual professional GPUs with plenty of memory each, in a format that
                            takes four drives and runs quietly enough to sit in a room with
                            people in it. For rendering, inference and shared compute.
                        </p>
                        <p class="mt-4 text-xs text-biz-text-secondary">Illustrative image</p>
                        <div class="mt-6">
                            <a href="{{ route('builder') }}"
                               class="inline-flex items-center gap-2 rounded-2xl bg-biz-primary px-5 py-2.5 font-bold text-white transition hover:bg-biz-primary-hover">
                                Configure one
                            </a>
                        </div>
                    </div>
                </article>

                {{-- Supplied: the full PCTG Business lockup on dark. --}}
                <article class="flex flex-col justify-center rounded-3xl border border-white/5 bg-biz-surface p-7">
                    <img
                        src="{{ asset('img/business/logo-dark.png') }}"
                        alt="PCTechGuy Business"
                        width="640"
                        height="640"
                        loading="lazy"
                        decoding="async"
                        class="mx-auto w-full max-w-[15rem]"
                    >
                    <h3 class="mt-6 text-center text-2xl font-black">Technology That Means Business</h3>
                    <p class="mt-4 text-center text-sm leading-relaxed text-biz-text-secondary">
                        Every system comes with the same things our gaming builds do: parts
                        checked against your actual workload, a machine that is assembled and
                        tested before it ships, UK delivery included in the price, and a
                        two-year warranty on the whole system rather than on the bits.
                    </p>
                    <div class="mt-8 grid gap-3 sm:grid-cols-2">
                        @foreach ([
                            'Workload-checked parts',
                            'Assembled and tested',
                            'UK delivery included',
                            'Two-year warranty',
                        ] as $promise)
                            <div class="flex items-start gap-2 text-sm text-biz-text-secondary">
                                <x-pctg.icon name="shield-check" class="mt-0.5 h-4 w-4 shrink-0 text-biz-accent" />
                                <span>{{ $promise }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-8 text-center">
                        <a href="{{ route('support') }}"
                           class="inline-flex items-center gap-2 rounded-2xl border border-biz-primary/40 bg-white/5 px-6 py-3 font-bold text-white transition hover:border-biz-primary">
                            Talk to us about your workload
                        </a>
                    </div>
                </article>
            </div>
        </div>
    </section>

    {{-- ---------------------------------------------------------------
         HOW IT WORKS
         --------------------------------------------------------------- --}}
    <section class="mx-auto max-w-[1600px] px-4 py-16 sm:px-6 lg:px-8">
        <h2 class="text-3xl font-black md:text-4xl">How a business build works</h2>

        <ol class="mt-10 grid gap-6 md:grid-cols-4">
            @foreach ([
                ['n' => '01', 't' => 'Tell us the work', 'b' => 'Which software, which project, and what actually goes wrong today — slow renders, dropped frames, or not enough memory.'],
                ['n' => '02', 't' => 'We spec it',      'b' => 'Parts chosen against that workload and your budget, with the reasoning stated so you can argue with it.'],
                ['n' => '03', 't' => 'You approve it',  'b' => 'A named spec with live prices and an all-in total. Nothing is ordered until you say yes.'],
                ['n' => '04', 't' => 'Built and tested','b' => 'Assembled, compatibility checked and tested in the UK, then delivered with the two-year warranty.'],
            ] as $step)
                <li class="rounded-2xl border border-white/5 bg-biz-surface p-6">
                    <span class="text-sm font-black text-biz-primary">{{ $step['n'] }}</span>
                    <h3 class="mt-2 text-lg font-bold">{{ $step['t'] }}</h3>
                    <p class="mt-3 text-sm leading-relaxed text-biz-text-secondary">{{ $step['b'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- ---------------------------------------------------------------
         CTA
         --------------------------------------------------------------- --}}
    <section class="border-t border-white/5">
        <div class="mx-auto max-w-3xl px-4 py-20 text-center sm:px-6 lg:px-8">
            <h2 class="text-3xl font-black md:text-5xl">Let's spec your system</h2>
            <p class="mt-5 text-biz-text-secondary">
                Tell us the software and the workload. If a spec is going to be the wrong
                answer, we will say so before you spend anything.
            </p>
            <div class="mt-10 flex flex-wrap justify-center gap-4">
                <a href="{{ route('builder') }}"
                   class="inline-flex items-center gap-2 rounded-2xl bg-biz-primary px-7 py-3.5 font-bold text-white shadow-glow-biz transition hover:bg-biz-primary-hover">
                    Open the builder
                </a>
                <a href="{{ route('support') }}"
                   class="inline-flex items-center gap-2 rounded-2xl border border-biz-primary/40 px-7 py-3.5 font-bold text-white transition hover:border-biz-primary">
                    Contact us
                </a>
            </div>
        </div>
    </section>
</div>