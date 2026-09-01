<?php

namespace App\Console\Commands;

use App\Services\FPSCalculationService;
use Illuminate\Console\Command;

class ClearBenchmarkCache extends Command
{
    protected $signature = 'pctg:clear-benchmarks';

    protected $description = 'Flush all cached FPS benchmark data';

    public function handle(FPSCalculationService $fps): int
    {
        if (! $fps->flushAll()) {
            $this->warn('The active cache driver does not support tags.');
            $this->warn('Switch CACHE_STORE to redis, or run `php artisan cache:clear` to clear everything.');

            return self::SUCCESS;
        }

        $this->info('Benchmark cache cleared.');

        return self::SUCCESS;
    }
}
