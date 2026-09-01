<?php

namespace Database\Seeders;

use App\Models\Benchmark;
use App\Models\Component;
use Illuminate\Database\Seeder;

class BenchmarkSeeder extends Seeder
{
    public function run(): void
    {
        $cpus = [
            'amd-ryzen-9700x' => [
                // RTX 5070 Ti
                ['gpu' => 'rtx-5070-ti', 'game' => 'Warzone', 'fps' => 145],
                ['gpu' => 'rtx-5070-ti', 'game' => 'Fortnite', 'fps' => 220],
                ['gpu' => 'rtx-5070-ti', 'game' => 'Cyberpunk 2077', 'fps' => 95],
                ['gpu' => 'rtx-5070-ti', 'game' => 'CS2', 'fps' => 350],
                // RTX 5080
                ['gpu' => 'rtx-5080', 'game' => 'Warzone', 'fps' => 165],
                ['gpu' => 'rtx-5080', 'game' => 'Fortnite', 'fps' => 240],
                ['gpu' => 'rtx-5080', 'game' => 'Cyberpunk 2077', 'fps' => 112],
                ['gpu' => 'rtx-5080', 'game' => 'CS2', 'fps' => 380],
                // RX 9070 XT
                ['gpu' => 'rx-9070-xt', 'game' => 'Warzone', 'fps' => 140],
                ['gpu' => 'rx-9070-xt', 'game' => 'Fortnite', 'fps' => 210],
                ['gpu' => 'rx-9070-xt', 'game' => 'Cyberpunk 2077', 'fps' => 88],
                ['gpu' => 'rx-9070-xt', 'game' => 'CS2', 'fps' => 330],
            ],
            'amd-ryzen-7800x3d' => [
                ['gpu' => 'rtx-5070-ti', 'game' => 'Warzone', 'fps' => 150],
                ['gpu' => 'rtx-5070-ti', 'game' => 'Fortnite', 'fps' => 235],
                ['gpu' => 'rtx-5070-ti', 'game' => 'Cyberpunk 2077', 'fps' => 98],
                ['gpu' => 'rtx-5070-ti', 'game' => 'CS2', 'fps' => 370],
                ['gpu' => 'rtx-5080', 'game' => 'Warzone', 'fps' => 170],
                ['gpu' => 'rtx-5080', 'game' => 'Fortnite', 'fps' => 255],
                ['gpu' => 'rtx-5080', 'game' => 'Cyberpunk 2077', 'fps' => 115],
                ['gpu' => 'rtx-5080', 'game' => 'CS2', 'fps' => 400],
                ['gpu' => 'rx-9070-xt', 'game' => 'Warzone', 'fps' => 145],
                ['gpu' => 'rx-9070-xt', 'game' => 'Fortnite', 'fps' => 220],
                ['gpu' => 'rx-9070-xt', 'game' => 'Cyberpunk 2077', 'fps' => 90],
                ['gpu' => 'rx-9070-xt', 'game' => 'CS2', 'fps' => 345],
            ],
            'intel-core-i5-14600k' => [
                ['gpu' => 'rtx-5070-ti', 'game' => 'Warzone', 'fps' => 140],
                ['gpu' => 'rtx-5070-ti', 'game' => 'Fortnite', 'fps' => 215],
                ['gpu' => 'rtx-5070-ti', 'game' => 'Cyberpunk 2077', 'fps' => 92],
                ['gpu' => 'rtx-5070-ti', 'game' => 'CS2', 'fps' => 340],
                ['gpu' => 'rtx-5080', 'game' => 'Warzone', 'fps' => 160],
                ['gpu' => 'rtx-5080', 'game' => 'Fortnite', 'fps' => 232],
                ['gpu' => 'rtx-5080', 'game' => 'Cyberpunk 2077', 'fps' => 108],
                ['gpu' => 'rtx-5080', 'game' => 'CS2', 'fps' => 365],
                ['gpu' => 'rx-9070-xt', 'game' => 'Warzone', 'fps' => 135],
                ['gpu' => 'rx-9070-xt', 'game' => 'Fortnite', 'fps' => 205],
                ['gpu' => 'rx-9070-xt', 'game' => 'Cyberpunk 2077', 'fps' => 86],
                ['gpu' => 'rx-9070-xt', 'game' => 'CS2', 'fps' => 320],
            ],
        ];

        foreach ($cpus as $cpuSlug => $benchmarks) {
            $cpu = Component::where('slug', $cpuSlug)->firstOrFail();

            foreach ($benchmarks as $benchmark) {
                Benchmark::updateOrCreate(
                    [
                        'cpu_id' => $cpu->id,
                        'gpu_id' => Component::where('slug', $benchmark['gpu'])->firstOrFail()->id,
                        'game' => $benchmark['game'],
                        'resolution' => '1440P',
                    ],
                    [
                        'fps' => $benchmark['fps'],
                        'settings' => 'Ultra',
                    ]
                );
            }
        }
    }
}
