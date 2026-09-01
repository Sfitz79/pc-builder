<x-pctg.layouts.builder>

    <div
        class="space-y-6"
        x-data="builderState()"
    >

        @include('builder.partials.ai-wizard')

        <div id="build-results" class="grid gap-6 lg:grid-cols-12">

            <div class="lg:col-span-8 space-y-6">

                @include('builder.partials.recommendations')

                @include('builder.partials.component-grid')

            </div>

            <div class="lg:col-span-4 space-y-6">

                @include('builder.partials.build-summary')

                @include('builder.partials.compatibility')

                @include('builder.partials.upgrade-suggestions')

                @include('builder.partials.build-health')

                @include('builder.partials.fps-panel')

            </div>

        </div>

        <x-pctg.component-selector />

    </div>

</x-pctg.layouts.builder>
