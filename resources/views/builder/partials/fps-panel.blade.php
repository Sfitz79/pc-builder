<x-pctg.card>

    <x-pctg.section-heading
        title="Expected FPS"
        description="Estimated FPS based on the selected CPU and GPU"
    />

    <div class="mt-6 space-y-5">

        <div
            x-show="fpsResults.length === 0"
            class="text-center text-slate-400 py-10"
        >
            Select a CPU and GPU to view FPS estimates.
        </div>

        <template x-for="row in fpsResults" :key="row.game">

            <div>

                <div class="flex justify-between mb-2">

                    <span x-text="row.game"></span>

                    <span class="font-semibold" x-text="row.fps + ' FPS'"></span>

                </div>

                <div class="pctg-progress">
                    <div
                        class="pctg-progress-bar"
                        x-bind:style="'width: ' + Math.min(100, row.fps / 4) + '%'"
                    ></div>
                </div>

            </div>

        </template>

    </div>

</x-pctg.card>
