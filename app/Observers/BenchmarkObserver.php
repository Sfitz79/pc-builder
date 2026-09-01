<?php

namespace App\Observers;

use App\Models\Benchmark;
use App\Services\FPSCalculationService;

class BenchmarkObserver
{
    public function __construct(
        protected FPSCalculationService $fps
    ) {
    }

    /**
     * Clear the cached FPS data for this benchmark whenever it is created
     * or updated.
     */
    public function saved(Benchmark $benchmark): void
    {
        $this->fps->forget($benchmark->cpu_id, $benchmark->gpu_id, $benchmark->resolution);
    }

    public function deleted(Benchmark $benchmark): void
    {
        $this->fps->forget($benchmark->cpu_id, $benchmark->gpu_id, $benchmark->resolution);
    }
}
