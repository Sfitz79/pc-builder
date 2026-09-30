<x-pctg.layouts.builder
    :title="$build->name"
    active="builds"
>

    @push('schema')
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@type": "Product",
            "name": {!! json_encode($build->name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
            "description": "Custom gaming PC built by {{ $build->user->name ?? 'PCTG Community' }}{{ $build->purpose ? ', optimised for ' . $build->purpose : '' }}{{ $build->resolution ? ', targeting ' . $build->resolution : '' }}. Configured with the PCTG AI builder and assembled in the UK.",
            "image": "{{ url('/img/logo.svg') }}",
            "@id": "{{ url()->current() }}#product",
            "brand": {
                "@type": "Brand",
                "name": "PCTechGuy Online"
            },
            "offers": {
                "@type": "Offer",
                "priceCurrency": "GBP",
                "price": {{ number_format($build->total_price, 2, '.', '') }},
                "availability": "https://schema.org/PreOrder",
                "url": "{{ url()->current() }}",
                "itemCondition": "https://schema.org/NewCondition",
                "seller": {
                    "@id": "{{ url('/') }}#organization"
                }
            },
            "additionalProperty": [
                @foreach ($build->components as $index => $part)
                {
                    "@type": "PropertyValue",
                    "name": {!! json_encode($part->category?->name ?? $part->pivot->category, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
                    "value": {!! json_encode($part->name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
                }{{ ! $loop->last ? ',' : '' }}
                @endforeach
            ]
        }
        </script>
    @endpush

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
                        :image="$part->displayImage()"
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
