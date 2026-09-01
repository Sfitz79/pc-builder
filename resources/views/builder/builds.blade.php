<x-pctg.layouts.builder
    title="My Saved Builds"
    active="builds"
>

    <div class="space-y-6">

        <x-pctg.card>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <x-pctg.badge>
                        💾 Build History
                    </x-pctg.badge>

                    <h1 class="mt-4 text-3xl font-black md:text-4xl">
                        My Saved Builds
                    </h1>

                    <p class="mt-2 max-w-2xl text-slate-400">
                        Every build you save is stored here, ready to reload, share, or checkout.
                    </p>
                </div>

                <x-pctg.button href="/builder">
                    New Build
                </x-pctg.button>
            </div>

        </x-pctg.card>

        @if ($builds->isEmpty())

            <x-pctg.empty-state
                title="No builds saved yet"
                message="Head to the builder and hit Save Build to start your collection."
            />

        @else

            <div class="grid gap-4 md:grid-cols-2">

                @foreach ($builds as $build)

                    <div class="pctg-card-hover">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-white">
                                    {{ $build->name }}
                                </h3>

                                <p class="mt-1 text-sm text-slate-400">
                                    {{ $build->components->count() }} components
                                    @if ($build->resolution)
                                        · {{ $build->resolution }}
                                    @endif
                                </p>
                            </div>

                            <span class="font-bold text-red-400">
                                £{{ number_format($build->total_price, 0) }}
                            </span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-pctg.badge variant="success">
                                Score {{ $build->performance_score }}
                            </x-pctg.badge>

                            @if ($build->purpose)
                                <x-pctg.stat-tag>
                                    {{ ucfirst($build->purpose) }}
                                </x-pctg.stat-tag>
                            @endif
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <x-pctg.button variant="secondary" :href="route('builder')">
                                Reload
                            </x-pctg.button>

                            @if ($build->share_slug)
                                <x-pctg.button variant="secondary" :href="route('build.show', $build->share_slug)">
                                    Share
                                </x-pctg.button>
                            @endif
                        </div>
                    </div>

                @endforeach

            </div>

        @endif

    </div>

</x-pctg.layouts.builder>
