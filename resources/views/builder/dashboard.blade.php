<x-pctg.layouts.builder>

    {{-- x-data was REMOVED from this wrapper.

     builderState() now lives on components/pctg/layouts/builder.blade.php, at
     the layout root. Having it here as well initialised the 83-key state a
     second time, nested inside the first, and the inner instance shadowed the
     outer one for everything below - which is why the component selector and
     the build summary could not see it.

     This div is kept purely for its vertical rhythm. --}}
    <div
        class="space-y-6"
    >

        @include('builder.partials.ai-wizard')

        <div id="build-results" class="grid gap-6 lg:grid-cols-12">

            <div class="lg:col-span-8 space-y-6">

                @include('builder.partials.recommendations')

                @include('builder.partials.component-grid')

            </div>

            <div class="lg:col-span-4 space-y-6">

                @include('builder.partials.build-3d')

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
