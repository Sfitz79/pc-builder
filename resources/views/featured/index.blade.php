@extends('layouts.app')

@section('title', 'PCTG Featured Systems — Gaming, Streaming, Studio')
@section('description', 'Every PCTG system, tagged by what it is for, the resolution it targets and where it sits on performance. Filterable, with the full parts list on every build.')

{{--
    The featured range.

    Three CLOSED axes - usage, resolution, tier. The filter controls are built
    from config/featured_builds.php rather than hard-coded, and the controller
    discards any filter value the taxonomy does not contain. An open dropdown
    would eventually offer "4K / low / streaming", which is not a product we
    build.

    No product imagery yet: the Boss is supplying those separately, so each card
    reserves an image area that renders nothing rather than a placeholder we would
    have to retract later.
--}}

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 md:px-8">

    <header class="mb-8">
        <p class="text-sm font-semibold uppercase tracking-widest text-red-500">PCTG Systems</p>
        <h1 class="mt-2 text-3xl font-black text-white md:text-5xl">Featured Systems</h1>
        <p class="mt-4 max-w-3xl text-slate-300">
            Every system below is built from parts we actually stock, checked against
            our compatibility and component rules. Filter by what you need it for,
            the resolution you play or work at, and how much headroom you want.
        </p>
    </header>

    @if ($empty)
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-6 text-amber-200">
            <p class="font-semibold">The featured range is being prepared.</p>
            <p class="mt-1">Our full custom builder is available now if you want to start straight away.</p>
            <a href="{{ route('builder') }}" class="mt-4 inline-block rounded-lg bg-red-500 px-5 py-2 font-bold text-white">
                Open the builder
            </a>
        </div>
    @else

        {{-- Filters: built from the taxonomy, so every option is a real product --}}
        <form method="GET" action="{{ route('featured') }}"
              class="mb-10 grid gap-4 rounded-xl border border-white/10 bg-white/5 p-5 md:grid-cols-4">
            <div>
                <label for="usage" class="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-400">Built for</label>
                <select id="usage" name="usage" class="w-full rounded-lg border border-white/15 bg-slate-900 px-3 py-2 text-white">
                    <option value="">All uses</option>
                    @foreach ($taxonomy['axes']['usage'] as $key => $label)
                        <option value="{{ $key }}" @selected($usage === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="resolution" class="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-400">Resolution</label>
                <select id="resolution" name="resolution" class="w-full rounded-lg border border-white/15 bg-slate-900 px-3 py-2 text-white">
                    <option value="">Any resolution</option>
                    @foreach ($taxonomy['axes']['resolution'] as $res)
                        <option value="{{ $res }}" @selected($resolution === $res)>{{ $res }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tier" class="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-400">Performance</label>
                <select id="tier" name="tier" class="w-full rounded-lg border border-white/15 bg-slate-900 px-3 py-2 text-white">
                    <option value="">Any performance</option>
                    @foreach ($taxonomy['axes']['tier'] as $key => $label)
                        <option value="{{ $key }}" @selected($tier === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-lg bg-red-500 px-5 py-2 font-bold text-white hover:bg-red-600">
                    Show
                </button>
            </div>
        </form>

        <p class="mb-6 text-sm text-slate-400">
            Showing {{ count($builds) }} of {{ $total_available }} systems.
        </p>

        @if ($builds === [])
            <p class="rounded-xl border border-white/10 bg-white/5 p-8 text-center text-slate-300">
                No system matches that combination. Try widening one of the filters.
            </p>
        @endif

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($builds as $build)
                <article class="flex flex-col rounded-2xl border border-white/10 bg-white/5 p-6">

                    {{-- Tier tags: the three closed axes, always shown --}}
                    <div class="mb-3 flex flex-wrap gap-2">
                        <span class="rounded-full bg-red-500/15 px-3 py-1 text-xs font-bold text-red-300">
                            {{ $build['tags']['usage_label'] }}
                        </span>
                        <span class="rounded-full bg-slate-700/60 px-3 py-1 text-xs font-semibold text-slate-200">
                            {{ $build['tags']['resolution'] }}
                        </span>
                        <span class="rounded-full bg-slate-700/60 px-3 py-1 text-xs font-semibold text-slate-200">
                            {{ $build['tags']['tier_label'] }}
                        </span>
                    </div>

                    {{-- Product imagery supplied separately by the Boss. Deliberately
                         empty rather than a placeholder we would have to retract. --}}
                    <div class="mb-4 flex aspect-video items-center justify-center rounded-xl border border-dashed border-white/10 bg-black/20">
                        <span class="text-xs uppercase tracking-widest text-slate-600">Image pending</span>
                    </div>

                    <h2 class="text-xl font-black text-white">{{ $build['name'] }}</h2>

                    <p class="mt-2 text-sm text-slate-300">{{ $build['summary'] }}</p>

                    @if (! empty($build['promise']))
                        <p class="mt-2 text-xs text-slate-400">{{ $build['promise'] }}</p>
                    @endif

                    <p class="mt-4 text-2xl font-black text-white">
                        &pound;{{ number_format($build['verified_total'], 2) }}
                    </p>

                    <details class="mt-4 text-sm">
                        <summary class="cursor-pointer font-semibold text-slate-300 hover:text-white">
                            What's inside ({{ count($build['parts']) }} parts)
                        </summary>
                        <ul class="mt-3 space-y-1 border-l-2 border-white/10 pl-3">
                            @foreach ($build['parts'] as $part)
                                <li class="flex justify-between gap-3">
                                    <span class="text-slate-400">{{ $part['type'] }}</span>
                                    <span class="flex-1 text-right text-slate-200">{{ $part['name'] }}</span>
                                    <span class="text-slate-400">&pound;{{ number_format($part['price'], 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>

                    @if (! empty($build['integrated_graphics']))
                        <p class="mt-4 rounded-lg border border-amber-500/25 bg-amber-500/10 p-3 text-xs text-amber-200">
                            <strong class="font-semibold">No dedicated graphics card.</strong>
                            Graphics come from the processor. Built for esports and
                            1080p gaming, not for video editing, 3D or AAA titles.
                        </p>
                    @endif

                    <div class="mt-5 flex gap-3">
                        <a href="{{ route('builder') }}?featured={{ $build['slug'] }}"
                           class="flex-1 rounded-lg bg-red-500 px-4 py-2 text-center font-bold text-white hover:bg-red-600">
                            Build this
                        </a>
                        <a href="{{ route('builder.manual') }}?featured={{ $build['slug'] }}"
                           class="rounded-lg border border-white/20 px-4 py-2 font-semibold text-white hover:border-white/50">
                            Fine-tune
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
@endsection