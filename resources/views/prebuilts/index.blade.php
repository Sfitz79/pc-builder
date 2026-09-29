<x-app-layout
    :title="'Pre-Built Gaming PCs'"
    :description="'Hand-tuned PCTG pre-built gaming PCs - pick a build, tweak it in the AI builder, and order a system that is assembled and tested in the UK.'"
>

    @push('schema')
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "CollectionPage",
            "name": "Pre-Built Gaming PCs",
            "description": "Hand-tuned PCTG pre-built gaming PCs, configurable in the AI builder and assembled in the UK.",
            "url": "{{ url()->current() }}",
            "mainEntity": {
                "@@type": "ItemList",
                "itemListElement": [
                    @foreach ($builds as $index => $build)
                    {
                        "@@type": "ListItem",
                        "position": {{ $index + 1 }},
                        "item": {
                            "@@type": "Product",
                            "name": {!! json_encode($build['name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
                            "offers": {
                                "@@type": "Offer",
                                "priceCurrency": "GBP",
                                "price": {{ number_format($build['total'], 2, '.', '') }},
                                "availability": "https://schema.org/PreOrder"
                            }
                        }
                    }{{ ! $loop->last ? ',' : '' }}
                    @endforeach
                ]
            }
        }
        </script>
    @endpush

    <div class="space-y-8">

        {{-- Hero --}}
        <section>
            <x-pctg.hero class="pctg-reveal">
                <x-pctg.badge variant="gaming" dot>
                    Backed by the AI Builder
                </x-pctg.badge>
                <h1 class="mt-4 text-4xl font-black md:text-5xl">
                    Pre-Built Gaming PCs
                </h1>
                <p class="mt-4 max-w-2xl text-lg text-slate-400">
                    Real builds, assembled from the live UK component catalogue and tuned for
                    the games you actually play. Every one can be tweaked before you order.
                </p>
            </x-pctg.hero>
        </section>

        {{-- The builds --}}
        @forelse ($builds as $build)
            <section class="pctg-reveal">
                <x-pctg.card>
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-white/5 pb-6">
                        <div>
                            <x-pctg.badge variant="ai">
                                {{ $build['socket'] }}
                            </x-pctg.badge>
                            <h2 class="mt-3 text-2xl font-black md:text-3xl">
                                {{ $build['name'] }}
                            </h2>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($build['case_form_factor'])
                                    <x-pctg.badge variant="secondary">
                                        {{ $build['case_form_factor'] }} case
                                    </x-pctg.badge>
                                @endif
                                @if ($build['estimated_draw_watts'])
                                    <x-pctg.badge variant="secondary">
                                        ~{{ number_format($build['estimated_draw_watts']) }}W draw
                                    </x-pctg.badge>
                                @endif
                                @if ($build['psu_watts'])
                                    <x-pctg.badge variant="secondary">
                                        {{ $build['psu_watts'] }}W PSU
                                    </x-pctg.badge>
                                @endif
                                @if ($build['headroom'])
                                    <x-pctg.badge variant="success">
                                        {{ $build['headroom'] }}x PSU headroom
                                    </x-pctg.badge>
                                @endif
                                @if ($build['case_form_factor'] === 'ATX' && ($build['evidence']['board_chipset_class'] ?? null))
                                    <x-pctg.badge variant="success">
                                        Board class matches the CPU
                                    </x-pctg.badge>
                                @endif
                            </div>
                        </div>

                        <div class="text-right">
                            <p class="text-xs uppercase tracking-widest text-slate-400">
                                Estimated Total
                            </p>
                            <p class="mt-1 text-4xl font-black text-red-400">
                                £{{ number_format($build['total']) }}
                            </p>
                            <p class="mt-2 max-w-xs text-xs leading-relaxed text-yellow-300/80">
                                {{ $disclaimer }}
                            </p>
                        </div>
                    </div>

                    {{-- Parts table --}}
                    <div class="mt-6 overflow-hidden rounded-2xl border border-white/5">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-white/[0.03] text-xs uppercase tracking-widest text-slate-400">
                                <tr>
                                    <th class="px-4 py-3 font-medium">Component</th>
                                    <th class="hidden px-4 py-3 font-medium sm:table-cell">Part</th>
                                    <th class="px-4 py-3 text-right font-medium">Price</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                @foreach ($build['parts'] as $part)
                                    <tr>
                                        <td class="px-4 py-3 text-slate-300">{{ $part['typeLabel'] }}</td>
                                        <td class="hidden px-4 py-3 text-slate-400 sm:table-cell">{{ $part['name'] }}</td>
                                        <td class="px-4 py-3 text-right text-slate-200">£{{ number_format($part['price'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-white/[0.03]">
                                <tr>
                                    <td colspan="2" class="px-4 py-3 font-bold text-white">Estimated Total</td>
                                    <td class="px-4 py-3 text-right font-black text-red-400">£{{ number_format($build['total']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Honest build-evidence strip --}}
                    <div class="mt-5 grid gap-3 text-xs text-slate-400 md:grid-cols-2">
                        <p>
                            <span class="font-semibold text-slate-300">Prices.</span>
                            Shown as current catalogue estimates. Merchant-verified live pricing is
                            not available for every part yet, so the checkout always confirms your
                            exact figure before you pay.
                        </p>
                        @if ($build['evidence']['cooler_thermal_evidence'] ?? null)
                            <p>
                                <span class="font-semibold text-slate-300">Cooling.</span>
                                Cooler capacity is not thermally verified in the catalogue; final
                                thermals are confirmed on our burn test report.
                            </p>
                        @endif
                        @if ($build['evidence']['gpu_length_fit'] ?? null)
                            <p>
                                <span class="font-semibold text-slate-300">GPU fit.</span>
                                Case GPU-length clearance is not machine-checked; we confirm fit
                                before building.
                            </p>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-pctg.button
                            href="{{ route('builder') }}?prebuilt={{ $build['slug'] }}"
                            variant="primary"
                        >
                            Tweak This Build
                        </x-pctg.button>
                        <x-pctg.button
                            href="{{ route('builder.manual') }}?prebuilt={{ $build['slug'] }}"
                            variant="secondary"
                        >
                            Build Your Own
                        </x-pctg.button>
                    </div>
                </x-pctg.card>
            </section>
        @empty
            <section class="pctg-reveal">
                <x-pctg.card>
                    <x-pctg.empty-state
                        title="Pre-builts are being tuned"
                        message="We are assembling the latest from the component catalogue. In the meantime, use the AI builder to get a build to your budget instantly."
                    />
                    <div class="mt-6">
                        <x-pctg.button href="{{ route('builder') }}" variant="primary">
                            Open the AI Builder
                        </x-pctg.button>
                    </div>
                </x-pctg.card>
            </section>
        @endforelse

        {{-- CTA --}}
        <section class="pctg-reveal">
            <x-pctg.hero class="text-center">
                <h2 class="text-3xl font-black md:text-4xl">
                    Want Something Different?
                </h2>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-slate-400">
                    Tell the AI Builder your budget, your games and your resolution -
                    it will assemble the best-value build it can, and you can
                    customise every part before you order.
                </p>
                <div class="mt-8 flex justify-center">
                    <x-pctg.button href="{{ route('builder') }}" variant="primary" size="lg">
                        Start Building Your Own
                    </x-pctg.button>
                </div>
            </x-pctg.hero>
        </section>

    </div>

</x-app-layout>