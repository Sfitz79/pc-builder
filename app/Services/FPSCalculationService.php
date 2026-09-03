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
     * Normalised performance index relative to a common baseline. Used only as
     * a scaled fallback when no exact benchmark row exists for the chosen
     * component (the scrape-driven catalogue exposes far more parts than have
     * hand-entered benchmarks). Higher = faster.
     *
     * @var array<string, int> keyed by lowercase pattern => index
     */
    protected array $gpuIndices = [
        'rtx 5090' => 118,
        'rtx 4090' => 112,
        'rx 7900 xtx' => 110,
        'rtx 5080' => 100,
        'rx 7900 xt' => 100,
        'rtx 4080' => 95,
        'rtx 5070 ti' => 88,
        'rtx 5070' => 82,
        'rx 9070 xt' => 85,
        'rx 9070' => 75,
        'rtx 4070 ti super' => 84,
        'rtx 4070 super' => 78,
        'rtx 4070' => 72,
        'rtx 4060' => 62,
        'rx 7800 xt' => 84,
        'rx 7700 xt' => 72,
        'rx 7600 xt' => 62,
        'rx 7600' => 58,
        'rtx 5060 ti' => 68,
        'rtx 5060' => 62,
        'rtx 5050' => 58,
        'arc b580' => 60,
        'arc b570' => 55,
        'arc a380' => 42,
        'arc a310' => 36,
        'rx 6800 xt' => 76,
        'rx 6700 xt' => 66,
        'rx 6600 xt' => 55,
        'rx 6500 xt' => 44,
        'rtx 3070 ti' => 76,
        'rtx 3060' => 58,
        'rtx 3050' => 46,
        'rtx 2080 ti' => 72,
        'rtx 2060' => 50,
        'gtx 1660 super' => 44,
        'gtx 1660 ti' => 43,
        'gtx 1080 ti' => 68,
        'gtx 1080' => 54,
    ];

    /**
     * CPU indices anchored so Ryzen 9700X = 100 (the heavy reference CPU for
     * the seeded benchmark grid).
     *
     * @var array<string, int>
     */
    protected array $cpuIndices = [
        'ryzen 9950x3d' => 108,
        'ryzen 9800x3d' => 106,
        'ryzen 9900x' => 105,
        'ryzen 7800x3d' => 103,
        'ryzen 9700x' => 100,
        'ryzen 7700x' => 95,
        'ryzen 9600x' => 90,
        'ryzen 7600x' => 85,
        'ryzen 7500f' => 82,
        'core ultra 9' => 106,
        'core ultra 7' => 100,
        'core ultra 5' => 92,
        'i9-14900k' => 105,
        'i7-14700k' => 100,
        'i9-13900k' => 102,
        'i5-14600k' => 97,
        'i7-13700k' => 96,
        'i5-13600k' => 94,
        'i7-12700k' => 90,
        'i5-12600k' => 88,
        'i5-12400f' => 78,
        'ryzen 5800x3d' => 80,
        'ryzen 5700x' => 70,
        'ryzen 5600' => 62,
        'i5-11400' => 58,
    ];

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
        // Prefer the exact curated grid cell for the requested resolution.
        $benchmarks = $this->exactBenchmarks($cpu, $gpu, $resolution);

        if ($benchmarks->isNotEmpty()) {
            return $benchmarks;
        }

        // No 1440P source for the exact pairing — fall back to the nearest
        // curated grid cell scaled by the GPUs'/CPUs' relative tier, then to
        // the requested resolution. This covers legacy parts without a chipset.
        $scaled = $this->benchmarksByTier($cpu, $gpu, $resolution);

        return $this->applyResolution($scaled, $resolution);
    }

    /**
     * Exact benchmark rows for the given CPU + GPU pairing (or GPU-only when no
     * CPU is selected) at the requested resolution.
     *
     * Benchmarks are stored at chipset level against a single representative
     * component per chipset (the first catalogue part for that chipset). We
     * therefore resolve each chosen part to its representative component id so
     * every AIB variant of the same GPU (or every retail version of the same
     * CPU) shares one curated grid cell, then query those ids directly.
     *
     * @return Collection<int, array{game: string, fps: int, resolution: string, settings: string}>
     */
    protected function exactBenchmarks(?Component $cpu, Component $gpu, string $resolution): Collection
    {
        $gpuId = $this->benchmarkedId($gpu, 'gpu');

        if ($gpuId === null) {
            return collect();
        }

        $cpuIds = [];
        if ($cpu !== null) {
            $cpuId = $this->benchmarkedId($cpu, 'cpu');
            if ($cpuId === null) {
                return collect();
            }
            $cpuIds[] = $cpuId;
        }

        $rows = Benchmark::query()
            ->where('gpu_id', $gpuId)
            ->when($cpu !== null, fn ($query) => $query->whereIn('cpu_id', $cpuIds))
            ->where('resolution', $resolution)
            ->orderBy('game')
            ->get();

        // When no CPU is chosen, average each game's FPS across the CPU grid so
        // a GPU-only estimate is a single representative figure per game.
        if ($cpu === null) {
            $rows = $rows->groupBy('game')->map(function ($group) {
                return $group->first()->forceFill([
                    'fps' => (int) round($group->avg('fps')),
                ]);
            })->values();
        }

        return $rows->map(fn (Benchmark $benchmark) => [
            'game' => $benchmark->game,
            'fps' => (int) round($benchmark->fps),
            'resolution' => $benchmark->resolution,
            'settings' => $benchmark->settings,
        ]);
    }

    /**
     * Resolve a chosen component to the component id that owns benchmark rows.
     *
     * When the part has a chipset we return the first component with that
     * chipset (the representative the seeder keyed rows to). When it has no
     * chipset we return its own id so any legacy rows still match. Returns null
     * only when no benchmarked representative exists.
     */
    protected function benchmarkedId(Component $component, string $kind): ?int
    {
        $chipset = $component->chipset;

        if (! filled($chipset)) {
            return $component->id;
        }

        $benchmarked = Component::query()
            ->where('chipset', $chipset)
            ->whereHas('category', fn ($q) => $q->where('slug', $kind))
            ->whereIn('id', Benchmark::distinct()->pluck($kind.'_id'))
            ->orderBy('id')
            ->first();

        return $benchmarked?->id;
    }

    /**
     * Build an estimate from a representative curated grid cell (GPU anchor ×
     * CPU anchor), scaling each frame count by the ratio of the chosen parts'
     * performance indices to the anchors'.
     *
     * Only 1440P rows are used as the scaling baseline; applyResolution() then
     * adjusts to the requested resolution so every output stays consistent.
     *
     * @return Collection<int, array{game: string, fps: int, resolution: string, settings: string}>
     */
    protected function benchmarksByTier(?Component $cpu, Component $gpu, string $resolution): Collection
    {
        $gpuIndex = $this->gpuIndex($gpu);
        $cpuIndex = $cpu !== null ? $this->cpuIndex($cpu) : null;

        $gpuAnchor = $this->nearestAnchorGpu($gpuIndex, $gpu->id);

        if ($gpuAnchor === null) {
            return collect();
        }

        $rows = Benchmark::query()
            ->where('gpu_id', $gpuAnchor->id)
            ->where('resolution', '1440P')
            ->orderBy('game')
            ->get();

        $gpuRatio = $gpuIndex / $this->gpuIndex($gpuAnchor);

        if ($cpu !== null && $cpuIndex !== null) {
            $cpuAnchor = $this->nearestAnchorCpu($cpuIndex, $cpu->id);

            if ($cpuAnchor !== null) {
                $withCpu = $rows->where('cpu_id', $cpuAnchor->id)->values();
                $rows = $withCpu->isNotEmpty() ? $withCpu : $rows;
                $cpuRatio = $cpuIndex / $this->cpuIndex($cpuAnchor);
            } else {
                $cpuRatio = 1.0;
            }
        } else {
            $cpuRatio = 1.0;
        }

        $factor = $gpuRatio * $cpuRatio;

        return $rows->map(fn (Benchmark $benchmark) => [
            'game' => $benchmark->game,
            'fps' => (int) round($benchmark->fps * $factor),
            'resolution' => $benchmark->resolution,
            'settings' => $benchmark->settings,
        ]);
    }

    /**
     * The curated benchmarked GPU whose performance index is nearest to the
     * chosen GPU, preferring one that already has rows.
     */
    protected function nearestAnchorGpu(int $gpuIndex, int $gpuId): ?Component
    {
        return $this->nearestAnchor($gpuIndex, $this->anchoredGpuIds(), $gpuId, 'gpu');
    }

    protected function nearestAnchorCpu(int $cpuIndex, int $cpuId): ?Component
    {
        return $this->nearestAnchor($cpuIndex, $this->anchoredCpuIds(), $cpuId, 'cpu');
    }

    protected function nearestAnchor(int $index, Collection $anchors, int $ownId, string $kind): ?Component
    {
        if ($anchors->isEmpty()) {
            return null;
        }

        return $anchors
            ->filter(fn (Component $component) => $component->id !== $ownId)
            ->sortBy(fn (Component $component) => abs($index - $this->indexFor($component, $kind)))
            ->first();
    }

    protected function anchoredGpuIds(): Collection
    {
        return Component::whereIn('id', Benchmark::distinct()->pluck('gpu_id'))->get();
    }

    protected function anchoredCpuIds(): Collection
    {
        return Component::whereIn('id', Benchmark::distinct()->pluck('cpu_id'))->get();
    }

    protected function indexFor(Component $component, string $kind): int
    {
        return $kind === 'cpu' ? $this->cpuIndex($component) : $this->gpuIndex($component);
    }

    /**
     * Resolve the performance index for a GPU by matching its chipset/model
     * against the tier table. Falls back to 100 (the RTX 5080 baseline) when
     * nothing matches, so an estimate is always produced rather than a gap.
     */
    protected function gpuIndex(Component $gpu): int
    {
        $chipset = strtolower((string) ($gpu->specs['chipset'] ?? ''));
        $name = strtolower((string) $gpu->name);
        $haystack = trim($chipset.' '.$name);

        foreach ($this->gpuIndices as $pattern => $index) {
            if (str_contains($haystack, $pattern)) {
                return $index;
            }
        }

        return 100;
    }

    protected function cpuIndex(Component $cpu): int
    {
        $name = strtolower((string) $cpu->name);

        foreach ($this->cpuIndices as $pattern => $index) {
            if (str_contains($name, $pattern)) {
                return $index;
            }
        }

        return 100;
    }

    /**
     * @param  Collection<int, Benchmark|array{game: string, fps: int, resolution: string, settings: string}>  $benchmarks
     * @return Collection<int, array{game: string, fps: int, resolution: string, settings: string}>
     */
    protected function applyResolution(Collection $benchmarks, string $resolution): Collection
    {
        $scale = match ($resolution) {
            '1080P' => 1.35,
            '4K' => 0.65,
            default => 1.0,
        };

        return $benchmarks->map(function ($benchmark) use ($scale, $resolution) {
            $game = $benchmark instanceof Benchmark ? $benchmark->game : $benchmark['game'];
            $fps = $benchmark instanceof Benchmark ? $benchmark->fps : $benchmark['fps'];
            $settings = $benchmark instanceof Benchmark ? $benchmark->settings : $benchmark['settings'];

            return [
                'game' => $game,
                'fps' => (int) round($fps * $scale),
                'resolution' => $resolution,
                'settings' => $settings,
            ];
        });
    }
}
