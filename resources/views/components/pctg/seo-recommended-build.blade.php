@props([
    'budget' => 1500,
    'purpose' => 'gaming',
    'resolution' => '1440P',
    'title' => null,
])

@php
    $shares = [
        'cpu' => 0.30,
        'gpu' => 0.40,
        'ram' => 0.08,
        'storage' => 0.07,
        'motherboard' => 0.07,
        'psu' => 0.04,
        'case' => 0.02,
        'cooler' => 0.02,
    ];

    $budget = (float) $budget;
    $hardCap = $budget * 0.95;
    $pools = App\Models\Category::active()->ordered()->get()
        ->mapWithKeys(fn (App\Models\Category $category) => [
            $category->slug => App\Models\Component::active()
                ->where('category_id', $category->id)
                ->where('stock', '>', 0)
                ->get(),
        ]);

    $spent = 0.0;
    $slack = 0.0;
    $selection = [];

    foreach ($pools as $slug => $pool) {
        if ($pool->isEmpty()) {
            continue;
        }

        $target = $budget * ($shares[$slug] ?? 0.03);
        $allow = $target + $slack;

        $affordable = $pool
            ->filter(fn (App\Models\Component $component) => (float) $component->price <= $allow
                && ($spent + (float) $component->price) <= $hardCap);

        if ($affordable->isEmpty()) {
            $affordable = $pool
                ->filter(fn (App\Models\Component $component) => ($spent + (float) $component->price) <= $hardCap);
        }

        $pick = $affordable->sortByDesc(fn (App\Models\Component $component) => (float) $component->price)->first();

        if ($pick === null) {
            continue;
        }

        $price = (float) $pick->price;
        $selection[$slug] = ['name' => $pick->name, 'price' => $price];
        $spent += $price;
        $slack = max(0.0, $allow - $price);
    }

    $total = round($spent, 2);
@endphp

@if ($selection !== [])
    <x-pctg.card class="mt-10">

        <h2 class="text-3xl font-bold">
            {{ $title ?? 'Recommended £' . number_format($budget) . ' Gaming PC Build' }}
        </h2>

        <p class="mt-3 text-sm text-slate-500">
            Prices are pulled live from our part catalogue on
            {{ now()->format('jS F Y') }} and reflect current UK market
            conditions. Current total:
            <span class="font-bold text-red-400">£{{ number_format($total, 0) }}</span>.
        </p>

        <div class="mt-8 space-y-3">

            @foreach ($selection as $slug => $component)
                <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-800/60 bg-slate-900/40 px-4 py-3">
                    <div class="min-w-0">
                        <span class="mr-2 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $slug }}</span>
                        <span class="font-medium">{{ $component['name'] }}</span>
                    </div>
                    <span class="shrink-0 font-bold text-red-400">£{{ number_format($component['price'], 0) }}</span>
                </div>
            @endforeach

            <div class="flex items-center justify-between px-4 py-2">
                <span class="font-semibold">Build Total</span>
                <span class="text-xl font-black">£{{ number_format($total, 0) }}</span>
            </div>

        </div>

        <div class="mt-6">
            <a href="{{ route('builder') }}" class="pctg-button">
                Generate This Build With AI
            </a>
        </div>

    </x-pctg.card>
@endif
