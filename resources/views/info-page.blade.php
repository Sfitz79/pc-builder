<x-app-layout :title="$title">

    {{-- Page hero --}}
    <section class="mb-12">
        <x-pctg.hero class="pctg-reveal">
            <h1 class="text-4xl font-black md:text-5xl">{{ $title }}</h1>
            <p class="mt-4 max-w-2xl text-lg text-slate-400">{{ $subtitle }}</p>
        </x-pctg.hero>
    </section>

    {{-- Feature cards --}}
    <section class="mb-12">
        <div class="grid gap-4 pctg-reveal md:grid-cols-3" style="--reveal-delay: 120ms">
            @foreach ($items as $item)
                <x-pctg.hover-card>
                    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-pctg-primary/10 text-pctg-primary-hover ring-1 ring-pctg-primary/30">
                        <x-pctg.icon name="{{ $item['icon'] }}" class="h-6 w-6" />
                    </div>
                    <h3 class="text-lg font-bold">{{ $item['title'] }}</h3>
                    <p class="mt-3 text-sm leading-relaxed text-slate-400">{{ $item['body'] }}</p>
                </x-pctg.hover-card>
            @endforeach
        </div>
    </section>

    {{-- CTA --}}
    <section class="mb-12">
        <x-pctg.hero class="pctg-reveal text-center">
            <h2 class="pctg-pulse text-3xl font-black md:text-4xl">Ready To Get Building?</h2>
            <p class="mx-auto mt-4 max-w-2xl text-lg text-slate-400">
                Everything above is a click away inside the AI builder — pick a budget, pick your games, done.
            </p>
            <div class="mt-8 flex justify-center">
                <x-pctg.button href="/builder" variant="primary" size="lg">
                    <x-pctg.icon name="sparkles" class="h-5 w-5" /> Start Building
                </x-pctg.button>
            </div>
        </x-pctg.hero>
    </section>

</x-app-layout>
