<?php

namespace App\Services;

use App\Models\Benchmark;
use App\Models\Component;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FPSCalculationService
{
    /**
     * Estimate FPS across games for a given CPU + GPU pairing.
     *
     * @return Collection<int, array{game: string, fps: int, resolution: string, settings: string}>
     */
    public function forComponents(?Component $cpu, ?Component $gpu, string $resolution = '1440P'): Collection
    {
        if ($gpu === null) {
            return collect();
        }

        if ($cpu === null) {
            return $this->queryBenchmarks($cpu, $gpu, $resolution);
        }

        return $this->cache()->remember(
            self::cacheKey($cpu->id, $gpu->id, $resolution),
            now()->addHours(12),
            fn (): Collection => $this->queryBenchmarks($cpu, $gpu, $resolution)
        );
    }

    /**
     * Estimate a single average FPS figure for health scoring.
     */
    public function average(?Component $cpu, ?Component $gpu, string $resolution = '1440P'): ?float
    {
        $results = $this->forComponents($cpu, $gpu, $resolution);

        if ($results->isEmpty()) {
            return null;
        }

        return round($results->avg('fps'), 1);
    }

    /**
     * Clear a single cached CPU/GPU/resolution combination.
     */
    public function forget(int $cpuId, int $gpuId, string $resolution): void
    {
        $this->cache()->forget(self::cacheKey($cpuId, $gpuId, $resolution));
    }

    /**
     * Flush every cached benchmark. Returns false when the active cache driver
     * cannot scope the flush (i.e. it does not support tags).
     */
    public function flushAll(): bool
    {
        if (! Cache::getStore() instanceof TaggableStore) {
            return false;
        }

        Cache::tags('benchmarks')->flush();

        return true;
    }

    public static function cacheKey(int $cpuId, int $gpuId, string $resolution): string
    {
        return sprintf('fps:%s:%s:%s', $cpuId, $gpuId, strtolower($resolution));
    }

    /**
     * Tag-aware cache handle. Falls back to the default store when the driver
     * does not support tags (file, database, array), so the service never breaks
     * on a default Laravel install.
     */
    protected function cache(): CacheRepository
    {
        if (Cache::getStore() instanceof TaggableStore) {
            return Cache::tags('benchmarks');
        }

        return Cache::store();
    }

    /**
     * @return Collection<int, array{game: string, fps: int, resolution: string, settings: string}>
     */
    protected function queryBenchmarks(?Component $cpu, Component $gpu, string $resolution): Collection
    {
        return Benchmark::query()
            ->where('gpu_id', $gpu->id)
            ->when($cpu, fn ($query) => $query->where('cpu_id', $cpu->id))
            ->when(! $cpu, fn ($query) => $query->orderBy('cpu_id'))
            ->where('resolution', $resolution)
            ->orderBy('game')
            ->get()
            ->map(fn (Benchmark $benchmark) => [
                'game' => $benchmark->game,
                'fps' => $benchmark->fps,
                'resolution' => $benchmark->resolution,
                'settings' => $benchmark->settings,
            ]);
    }
}
