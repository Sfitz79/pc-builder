<x-pctg.layouts.builder
    :title="$build->name"
    active="builds"
>

    <div class="space-y-6">

        <x-pctg.card>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <x-pctg.badge>
                        🚀 Shared Build
                    </x-pctg.badge>

                    <h1 class="mt-4 text-3xl font-black md:text-4xl">
                        {{ $build->name }}
                    </h1>

                    <p class="mt-2 text-sm text-slate-400">
                        by {{ $build->user->name ?? 'PCTG Community' }}
                        @if ($build->purpose)
                            · {{ ucfirst($build->purpose) }}
                        @endif
                        @if ($build->resolution)
                            · {{ $build->resolution }}
                        @endif
                    </p>
                </div>

                <div class="text-right">
                    <p class="text-xs uppercase tracking-widest text-slate-400">
                        Build Total
                    </p>
                    <p class="mt-1 text-4xl font-black text-red-400">
                        £{{ number_format($build->total_price, 0) }}
                    </p>

                    <x-pctg.badge variant="success" class="mt-2">
                        Score {{ $build->performance_score }}
                    </x-pctg.badge>
                </div>
            </div>

        </x-pctg.card>

        <x-pctg.card>

            <x-pctg.section-heading
                title="Selected Components"
            />

            <div class="mt-6 grid gap-4 md:grid-cols-2">

                @foreach ($build->components as $part)

                    <x-pctg.component-card
                        :title="$part->name"
                        :subtitle="$part->category?->name ?? $part->pivot->category"
                        :price="number_format($part->pivot->price_snapshot, 0)"
                    >
                        <x-pctg.badge variant="success">
                            Compatible
                        </x-pctg.badge>
                    </x-pctg.component-card>

                @endforeach

            </div>

        </x-pctg.card>

    </div>

</x-pctg.layouts.builder>
