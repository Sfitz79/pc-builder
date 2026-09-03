<?php

namespace App\Services;

use App\Models\AiRecommendation;
use App\Models\Category;
use App\Models\Component;

class AIRecommendationService
{
    public function __construct(protected GeminiService $gemini) {}

    /**
     * Return both the best-value build for the customer's budget AND the
     * ideal (newest/best) build regardless of price.
     *
     * @return array{budget: array, ideal: array}
     */
    public function recommendBoth(float $budget, ?string $purpose = null, ?string $resolution = null, ?int $userId = null): array
    {
        $categories = Category::active()->ordered()->get();
        $pools = $this->buildPools($categories, $purpose, $resolution);

        $ai = $this->gemini->scoreWeights($pools, $budget, $purpose, $resolution);

        if ($ai !== null) {
            $this->applyWeights($pools, $ai);
        }

        $budgetBuild = $this->pickBudgetBuild($pools, $budget);
        $idealBuild = $this->pickIdealBuild($categories, $purpose, $resolution);

        if ($userId !== null) {
            AiRecommendation::create([
                'user_id' => $userId,
                'budget' => $budget,
                'purpose' => $purpose,
                'resolution' => $resolution,
                'recommendation' => $budgetBuild['components'],
            ]);
        }

        $budgetBuild['ai'] = $ai !== null ? [
            'provider' => 'gemini',
            'model' => config('gemini.model'),
            'rationale' => $ai['rationale'] ?? null,
        ] : null;

        return [
            'budget' => $budgetBuild,
            'ideal' => $idealBuild,
        ];
    }

    /**
     * Pick the best value component per category within a budget.
     *
     * @return array{components: array<string, array{id: int, name: string, price: float, score: int}>, total: float, remaining: float, ai?: array<string, mixed>}
     */
    public function recommend(float $budget, ?string $purpose = null, ?string $resolution = null, ?int $userId = null): array
    {
        $categories = Category::active()->ordered()->get();
        $pools = $this->buildPools($categories, $purpose, $resolution);

        $ai = $this->gemini->scoreWeights($pools, $budget, $purpose, $resolution);

        if ($ai !== null) {
            $this->applyWeights($pools, $ai);
        }

        $result = $this->pickBudgetBuild($pools, $budget);

        if ($userId !== null) {
            AiRecommendation::create([
                'user_id' => $userId,
                'budget' => $budget,
                'purpose' => $purpose,
                'resolution' => $resolution,
                'recommendation' => $result['components'],
            ]);
        }

        if ($ai !== null) {
            $result['ai'] = [
                'provider' => 'gemini',
                'model' => config('gemini.model'),
                'rationale' => $ai['rationale'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Score every active component in every category and return pools keyed
     * by category slug, ready for the greedy pick loop.
     */
    protected function buildPools($categories, ?string $purpose, ?string $resolution): array
    {
        $pools = [];

        foreach ($categories as $category) {
            $pool = Component::active()
                ->where('category_id', $category->id)
                ->where('stock', '>', 0)
                ->get();

            if ($pool->isNotEmpty()) {
                $maxPrice = $pool->max(fn (Component $component) => (float) $component->price);
            } else {
                $maxPrice = 1.0;
            }

            $pools[$category->slug] = $pool
                ->each(fn (Component $component) => $component->score = $this->scoreComponent($component, $category, $purpose, $resolution, $maxPrice));
        }

        return $pools;
    }

    /**
     * Apply optional Gemini per-category weight multipliers to scored pools.
     */
    protected function applyWeights(array $pools, array $ai): void
    {
        foreach ($pools as $slug => $pool) {
            $weight = $ai['weights'][$slug] ?? 1.0;

            if (! is_numeric($weight) || (float) $weight <= 0) {
                continue;
            }

            $pool->each(
                fn (Component $component) => $component->score = (int) round($component->score * (float) $weight)
            );
        }
    }

    /**
     * Greedy pick that respects per-category budget shares and a hard cap.
     * Slack from under-spending in one category rolls forward to the next.
     */
    protected function pickBudgetBuild(array $pools, float $budget): array
    {
        $selection = [];
        $spent = 0.0;
        $slack = 0.0;
        $cpuSocket = null;
        $hardCap = $budget * 0.95;

        $shares = [
            'cpu' => 0.30,
            'gpu' => 0.40,
            'ram' => 0.08,
            'storage' => 0.07,
            'motherboard' => 0.07,
            'psu' => 0.04,
            'case' => 0.02,
            'cooler' => 0.02,
        ];

        foreach ($pools as $slug => $pool) {
            if ($pool->isEmpty()) {
                continue;
            }

            $allow = $budget * ($shares[$slug] ?? 0.05) + $slack;

            $candidates = $pool
                ->filter(fn (Component $component) => (float) $component->price <= $allow
                    && ($spent + (float) $component->price) <= $hardCap);

            if ($this->socketLocked($slug)) {
                if ($cpuSocket === null) {
                    continue;
                }

                $socketFits = fn (Component $component) => $this->fitsSocket($slug, $component, $cpuSocket);

                $candidates = $candidates
                    ->filter($socketFits)
                    ->sortByDesc('score');

                $pick = $candidates->sortByDesc('score')->first();

                if ($pick === null) {
                    $pick = $pool
                        ->filter(fn (Component $component) => $socketFits($component)
                            && ($spent + (float) $component->price) <= $hardCap)
                        ->sortByDesc('score')
                        ->first();
                }

                if ($pick === null) {
                    continue;
                }
            } else {
                $pick = $candidates->sortByDesc('score')->first();

                if ($pick === null) {
                    $pick = $pool
                        ->filter(fn (Component $component) => ($spent + (float) $component->price) <= $hardCap)
                        ->sortByDesc('score')
                        ->first();
                }

                if ($pick === null) {
                    continue;
                }
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $pick->socket;
            }

            $spent += (float) $pick->price;
            $slack = max(0.0, $allow - (float) $pick->price);
        }

        return [
            'components' => $selection,
            'total' => round($spent, 2),
            'remaining' => round(max(0, $budget - $spent), 2),
        ];
    }

    /**
     * Pick the absolute best (newest + highest performance) component per
     * category with no budget constraint — the "dream build" showcase.
     * Still enforces socket compatibility so the build is viable.
     */
    protected function pickIdealBuild($categories, ?string $purpose, ?string $resolution): array
    {
        $pools = $this->buildPools($categories, $purpose, $resolution);

        $selection = [];
        $spent = 0.0;
        $cpuSocket = null;

        foreach ($pools as $slug => $pool) {
            if ($pool->isEmpty()) {
                continue;
            }

            if ($this->socketLocked($slug)) {
                if ($cpuSocket === null) {
                    continue;
                }

                $socketFits = fn (Component $component) => $this->fitsSocket($slug, $component, $cpuSocket);
                $pick = $pool->filter($socketFits)->sortByDesc('score')->first();
            } else {
                $pick = $pool->sortByDesc('score')->first();
            }

            if ($pick === null) {
                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $pick->socket;
            }

            $spent += (float) $pick->price;
        }

        return [
            'components' => $selection,
            'total' => round($spent, 2),
            'remaining' => 0.0,
        ];
    }

    /**
     * Score a component for the AI build. The score balances two goals:
     *
     * 1. Newest-tech preference — a component's generation tier (platform)
     *    contributes a base bonus so current hardware is strongly preferred.
     * 2. Best value within that platform — within the same generation tier the
     *    option with the best performance per pound wins, rather than the most
     *    expensive or highest-PERF part.
     *
     * @param  float  $maxPrice  highest price in the category, used to normalise
     *                           price so value is comparable across categories
     */
    protected function scoreComponent(Component $component, Category $category, ?string $purpose, ?string $resolution, float $maxPrice): int
    {
        $specs = $component->specs ?? [];

        $generation = in_array($category->slug, ['cpu', 'gpu'], true)
            ? $this->generationScore($component, $category)
            : 0;

        $performance = match ($category->slug) {
            'cpu' => $this->scoreCpu($specs, $purpose),
            'gpu' => $this->scoreGpu($specs, $purpose, $resolution),
            'ram' => (int) ($specs['capacity'] ?? 0) >= 64 ? 80 : 60,
            'storage' => (int) str_replace('TB', '', $specs['capacity'] ?? $component->name) >= 2 ? 80 : 60,
            default => 60,
        };

        // Value: performance per unit of cost, relative to the category. A
        // cheap part that delivers near-flagship performance scores higher than
        // an expensive flagship that only edges ahead by a few percent.
        $priceNorm = max(min((float) $component->price / max($maxPrice, 1.0), 1.0), 0.1);
        $value = $performance / $priceNorm;

        $score = $generation > 0
            ? (int) round($generation * 0.35 + min($value, 100) * 0.65)
            : (int) round(min($value, 100));

        if ($component->wattage !== null) {
            $score += (int) max(0, 70 - (int) $component->wattage / 20);
        }

        return $score;
    }

    protected function scoreCpu(array $specs, ?string $purpose): int
    {
        $cores = (int) ($specs['cores'] ?? 8);

        return match ($purpose) {
            'streaming', 'creation' => min(100, 50 + ($cores - 8) * 8),
            'ai' => min(100, 55 + ($cores - 8) * 6),
            default => min(100, 50 + ($cores - 8) * 5),
        };
    }

    protected function scoreGpu(array $specs, ?string $purpose, ?string $resolution): int
    {
        $memory = str_replace(['GB', 'G'], '', $specs['memory'] ?? '8');
        $base = min(100, (int) $memory - 8) * 10 + 20;

        if ($purpose === 'ai') {
            $base += 15;
        }

        if ($resolution === '4K') {
            $base += 15;
        }

        return min(100, $base);
    }

    /**
     * Category slugs whose constituents must share the CPU socket with the
     * chosen CPU before they can be selected at all.
     */
    protected function socketLocked(string $slug): bool
    {
        return in_array($slug, ['motherboard', 'cooler'], true);
    }

    /**
     * Whether a component physically fits the CPU socket. Motherboards declare
     * the socket directly; coolers declare a supported_sockets spec list.
     * A cooler with no known socket list stays eligible (the compatibility
     * rule treats unknown sockets the same way).
     */
    protected function fitsSocket(string $slug, Component $component, ?string $cpuSocket): bool
    {
        if ($slug === 'motherboard') {
            return $component->socket === $cpuSocket;
        }

        if ($slug === 'cooler') {
            $supported = $component->specs['supported_sockets'] ?? null;

            if (empty($supported)) {
                return true;
            }

            return in_array($cpuSocket, $supported, true);
        }

        return true;
    }

    /**
     * How close a component is to the newest hardware generation (0-100).
     *
     * Explicit chipset maps give exact tiers for the parts that carry a
     * chipset; anything without one (or with an unknown family) falls back to
     * a generic board/architecture ladder. Scores are ordered newest-first so
     * the builder consciously drifts toward current-generation hardware.
     */
    protected function generationScore(Component $component, Category $category): int
    {
        if ($category->slug === 'cpu') {
            $tiers = [
                'Core Ultra 9 285K' => 100,
                'Core Ultra 7 265K' => 100,
                'Core Ultra 5 245K' => 100,
                'Ryzen 9 9950X' => 99,
                'Ryzen 9 9900X' => 98,
                'Ryzen 7 9800X3D' => 98,
                'Ryzen 7 9700X' => 95,
                'Ryzen 5 9600X' => 93,
                'Ryzen 7 7800X3D' => 90,
                'Ryzen 9 7950X' => 87,
                'Ryzen 9 7900X' => 86,
                'Ryzen 7 7700X' => 84,
                'Ryzen 7 7700' => 83,
                'Ryzen 5 7600X3D' => 85,
                'Ryzen 5 7600X' => 82,
                'Ryzen 5 7600' => 80,
                'Core i9-14900K' => 84,
                'Core i7-14700K' => 82,
                'Core i5-14600K' => 80,
                'Core i9-13900K' => 76,
                'Core i7-13700K' => 74,
                'Core i5-13600K' => 72,
                'Core i5-12600K' => 68,
                'Core i5-12400' => 64,
            ];

            if ($component->chipset !== null && isset($tiers[$component->chipset])) {
                return $tiers[$component->chipset];
            }

            // Unknown CPU chipset — fall back to a rough architecture ladder.
            return $this->cpuLadder($component);
        }

        if ($category->slug === 'gpu') {
            return $this->gpuLadder($component);
        }

        return 60;
    }

    /**
     * Rough architecture ladder for CPU chipsets not in the explicit tier map.
     */
    protected function cpuLadder(Component $component): int
    {
        $h = strtoupper((string) ($component->chipset ?: $component->name));

        return match (true) {
            preg_match('/CORE ULTRA \d [25]/', $h) === 1 => 100,
            preg_match('/RYZEN \d 9(?:8|9|5)0/', $h) === 1 => 97,
            preg_match('/RYZEN \d 9[57]00/', $h) === 1 => 94,
            preg_match('/RYZEN \d 7[679]00/', $h) === 1 => 87,
            preg_match('/CORE I9-14900K|CORE I7-14700K|CORE I5-14600K/', $h) === 1 => 82,
            preg_match('/CORE I9-13900K|CORE I7-13700K|CORE I5-13600K/', $h) === 1 => 76,
            preg_match('/CORE I5-12400|CORE I5-12600K/', $h) === 1 => 70,
            preg_match('/CORE ULTRA/', $h) === 1 => 100,
            preg_match('/RYZEN/', $h) === 1 => 85,
            preg_match('/CORE I/', $h) === 1 => 75,
            default => 60,
        };
    }

    /**
     * Architecture ladder for GPUs, keyed on chipset (or name when unknown).
     * Newest lineups rank highest so the builder gravitates to current tech.
     */
    protected function gpuLadder(Component $component): int
    {
        $h = strtoupper((string) ($component->chipset ?: $component->name));

        $ladder = [
            'RTX 5' => 100,
            'RX 9' => 97,
            'ARC B' => 92,
            'RTX 4' => 86,
            'RX 7' => 80,
            'RTX 3' => 72,
            'ARC A' => 66,
            'RX 6' => 60,
            'RTX 2' => 52,
        ];

        foreach ($ladder as $prefix => $score) {
            if (str_contains($h, $prefix)) {
                return $score;
            }
        }

        if (preg_match('/GTX 1[0-9]|RX 5/', $h) === 1) {
            return 40;
        }

        return 30;
    }
}
