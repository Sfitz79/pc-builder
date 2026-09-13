<?php

namespace App\Services;

use App\Models\AiRecommendation;
use App\Models\Category;
use App\Models\Component;

class AIRecommendationService
{
    public function __construct(protected GeminiService $gemini)
    {
        $this->pricing = app(BuildPricingService::class);
    }

    /**
     * Hidden-margin pricing engine (complete price = parts + margin).
     */
    protected BuildPricingService $pricing;

    /**
     * Categories that must all be present for a build to be complete.
     *
     * @var list<string>
     */
    protected array $requiredCategories = Component::REQUIRED_CATEGORIES;

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

        // Complete flag: prefers the decorated value (pickers already guarantee
        // completeness, including APU builds that legitimately skip the GPU).
        $budgetBuild['complete'] = $budgetBuild['complete'] ?? $this->isComplete($budgetBuild['components'], $this->hasApuComponents($budgetBuild['components']));
        $idealBuild['complete'] = $idealBuild['complete'] ?? $this->isComplete($idealBuild['components'], $this->hasApuComponents($idealBuild['components']));

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
        $result['complete'] = $this->isComplete($result['components']);

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
     *
     * The customer-facing budget is the SYSTEM budget (the one complete price
     * they see at checkout), so parts are picked against the effective parts
     * budget that leaves room for the hidden margin. The returned total is the
     * complete price — never a parts sum.
     */
    protected function pickBudgetBuild(array $pools, float $budget): array
    {
        // 1) Dedicated-GPU build.
        $gpu = $this->pickBalancedBuild($pools, $budget, forceGpu: true, needApu: false);
        if ($this->isComplete($gpu['components'])) {
            return $this->decorateBuild($gpu, $budget, 'gpu', apuWarning: false);
        }

        // 2) APU (no dedicated GPU) build — complete + warning.
        $apu = $this->pickBalancedBuild($pools, $budget, forceGpu: false, needApu: true);
        if ($this->isComplete($apu['components'], allowMissingGpu: true)) {
            return $this->decorateBuild($apu, $budget, 'apu', apuWarning: true);
        }

        // 3) Cheapest complete system we can physically build (nearest to budget).
        $cheapest = $this->pickCheapestComplete($pools);
        if ($cheapest !== null && $this->isComplete($cheapest['components'], allowMissingGpu: true)) {
            return $this->decorateBuild($cheapest, $budget, 'cheapest', apuWarning: $this->hasApuComponents($cheapest['components']));
        }

        // 4) Absolute fallback — keep whatever we have (should never happen).
        $components = array_merge($gpu['components'] ?? [], $apu['components'] ?? []);
        $spent = collect($components)->sum('price');

        return [
            'components' => $components,
            'total' => $this->pricing->completePrice($spent),
            'remaining' => 0.0,
            'complete' => false,
        ];
    }

    /**
     * Pick the best-scoring components within a budget while guaranteeing the
     * cursor moves through every category. When a category has no candidate
     * inside its share it relaxes to the cheapest component in that pool so
     * the system can still be completed (spending may exceed the budget — the
     * decorate step explains that in plain English).
     */
    protected function pickBalancedBuild(array $pools, float $budget, bool $forceGpu, bool $needApu): array
    {
        $selection = [];
        $spent = 0.0;
        $slack = 0.0;
        $cpuSocket = null;
        $cpuBrand = null;
        $partsBudget = $this->pricing->partsBudgetFor($budget);
        $hardCap = $partsBudget * 0.95;

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

        // Socket-locked categories (motherboard, cooler) DEPEND on the CPU so
        // they must run in a second pass, after the CPU socket is known.
        // Category order in the DB is not guaranteed (cooler can sort first).
        $ordered = array_merge(
            array_values(array_diff(array_keys($pools), ['motherboard', 'cooler'])),
            ['motherboard', 'cooler']
        );

        foreach ($ordered as $slug) {
            $pool = $pools[$slug] ?? collect();

            if ($pool->isEmpty()) {
                continue;
            }

            // APU mode has no GPU slot.
            if (! $forceGpu && $slug === 'gpu') {
                continue;
            }

            // Apply the quality floor so junk catalogue entries never ship.
            $pool = $this->viablePool($slug, $pool, $cpuBrand);

            // APU mode restricts the CPU to parts with built-in graphics.
            if ($needApu && $slug === 'cpu') {
                $pool = $pool->filter(fn (Component $component) => $this->hasIntegratedGraphics($component));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // RAM generation must match the CPU platform. The CPU is always
            // picked first in the loop, so the socket is known here; a real
            // motherboard that fits that socket inherently uses the same RAM
            // generation (AM4=DDR4, AM5=DDR5, LGA1700/1851=the platform's gen).
            if ($slug === 'ram' && $cpuSocket !== null) {
                $ramGen = $this->ramGenerationForSocket($cpuSocket);
                if ($ramGen !== null) {
                    $pool = $pool->filter(fn (Component $component) => $this->ramGenerationMatches($component, $ramGen));
                    if ($pool->isEmpty()) {
                        continue;
                    }
                }
            }

            $allow = $partsBudget * ($shares[$slug] ?? 0.05) + $slack;

            $candidates = $pool
                ->filter(fn (Component $component) => (float) $component->price <= $allow
                    && ($spent + (float) $component->price) <= $hardCap);

            if ($this->socketLocked($slug)) {
                if ($cpuSocket === null) {
                    continue;
                }

                $socketFits = fn (Component $component) => $this->fitsSocket($slug, $component, $cpuSocket);

                $pick = $candidates
                    ->filter($socketFits)
                    ->sortByDesc('score')
                    ->first();

                if ($pick === null) {
                    // No in-budget candidate that physically fits — relax to the
                    // cheapest compatible part so the system can still complete.
                    $pick = $pool
                        ->filter($socketFits)
                        ->sortBy('price')
                        ->first();
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
                    $pick = $pool->sortBy('price')->first();
                }
            }

            if ($pick === null) {
                if ($forceGpu && $slug === 'gpu') {
                    return ['components' => $selection, 'complete' => false];
                }

                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
                // Internal-only wattage used by the power-adequacy pass so a
                // GPU's real draw can never be paired with an undersized PSU.
                'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true) ? $this->componentWattage($slug, $pick) : 0,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $this->canonicalSocket($pick);
                $cpuBrand = $this->cpuBrandOf(['name' => $pick->name]);
            }

            $spent += (float) $pick->price;
            $slack = max(0.0, $allow - (float) $pick->price);
        }

        $selection = $this->ensurePowerAdequacy($selection, $pools);
        $spent = (float) collect($selection)->sum('price');

        return [
            'components' => $selection,
            'total' => $this->pricing->completePrice($spent),
            'remaining' => round(max(0, $budget - $this->pricing->completePrice($spent)), 2),
        ];
    }

    /**
     * The most cost-effective fully working system in the catalogue: cheapest
     * CPU with built-in graphics (APU), the cheapest compatible motherboard
     * and the cheapest part in every other category. No dedicated GPU — the
     * CPU's integrated graphics drive the display. Used when a budget cannot
     * complete even an APU build.
     */
    protected function pickCheapestComplete(array $pools): ?array
    {
        $selection = [];
        $spent = 0.0;
        $cpuSocket = null;
        $cpuBrand = null;

        foreach ($pools as $slug => $pool) {
            if ($pool->isEmpty()) {
                continue;
            }

            if ($slug === 'gpu') {
                continue; // Cheapest APU build has no dedicated GPU.
            }

            if ($slug === 'cpu') {
                $pool = $pool->filter(fn (Component $component) => $this->hasIntegratedGraphics($component));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            $pool = $this->viablePool($slug, $pool, $cpuBrand);

            // RAM generation must match the CPU platform (AM4=DDR4, AM5=DDR5,
            // LGA1700/1851=that platform's generation ring).
            if ($slug === 'ram' && $cpuSocket !== null) {
                $ramGen = $this->ramGenerationForSocket($cpuSocket);
                if ($ramGen !== null) {
                    $pool = $pool->filter(fn (Component $component) => $this->ramGenerationMatches($component, $ramGen));
                    if ($pool->isEmpty()) {
                        continue;
                    }
                }
            }

            $pick = $pool->sortBy('price')->first();

            if ($slug === 'motherboard' && $cpuSocket !== null) {
                $fitting = $pool
                    ->filter(fn (Component $component) => $component->socket === $cpuSocket)
                    ->sortBy('price')
                    ->first();

                if ($fitting !== null) {
                    $pick = $fitting;
                }
            }

            if ($pick === null) {
                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
                'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true) ? $this->componentWattage($slug, $pick) : 0,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $this->canonicalSocket($pick);
                $cpuBrand = $this->cpuBrandOf(['name' => $pick->name]);
            }

            $spent += (float) $pick->price;
        }

        if ($selection === []) {
            return null;
        }

        $selection = $this->ensurePowerAdequacy($selection, $pools);
        $spent = (float) collect($selection)->sum('price');

        return [
            'components' => $selection,
            'total' => $this->pricing->completePrice($spent),
            'remaining' => 0.0,
        ];
    }

    /**
     * Add every explainer a displayed build needs: one complete price, mode,
     * APU warning, cheapest-option flag and a plain-English pricing note tied
     * to supply and demand when a budget can't stretch to a complete system.
     */
    protected function decorateBuild(array $build, float $budget, string $mode, bool $apuWarning): array
    {
        $components = $build['components'] ?? [];
        $spent = (float) collect($components)->sum('price');
        $total = $this->pricing->completePrice($spent);
        $overBudget = $budget > 0 ? round(max(0.0, $total - $budget), 2) : 0.0;

        $explanation = null;

        if ($overBudget > 0) {
            $explanation = 'Component prices move with global supply and demand, and right now graphics cards and the newest memory cost a lot. With a budget of GBP '
                . number_format($budget)
                . ' we cannot build a brand-new gaming PC for that price today - this is the most cost-effective complete system we can put together, and the cheapest option available right now. You can still spread the cost: PayPal Pay in 3 lets you split the total into 3 interest-free payments.';
        }

        return [
            'components' => $components,
            'total' => $total,
            'remaining' => round(max(0, $budget - $total), 2),
            'complete' => true,
            'mode' => $mode,
            'apuWarning' => $apuWarning,
            'cheapestOption' => $overBudget > 0,
            'overBudget' => $overBudget,
            'explanation' => $explanation,
            'payments' => ['card', 'paypal_pay_in_3'],
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

        // Socket-locked categories come after the CPU so they can be matched.
        $ordered = array_merge(
            array_values(array_diff(array_keys($pools), ['motherboard', 'cooler'])),
            ['motherboard', 'cooler']
        );

        foreach ($ordered as $slug) {
            $pool = $pools[$slug] ?? collect();

            if ($pool->isEmpty()) {
                continue;
            }

            if ($this->socketLocked($slug)) {
                if ($cpuSocket === null) {
                    continue;
                }

                $socketFits = fn (Component $component) => $this->fitsSocket($slug, $component, $cpuSocket);
                $pick = $pool->filter($socketFits)->sortByDesc('score')->first();

                // No scored pick that fits — relax to the cheapest compatible
                // part so the dream build is still a buildable PC.
                if ($pick === null) {
                    $pick = $pool->filter($socketFits)->sortBy('price')->first();
                }
            } else {
                $pick = $pool->sortByDesc('score')->first()
                    ?? $pool->sortBy('price')->first();
            }

            if ($pick === null) {
                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
                'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true) ? $this->componentWattage($slug, $pick) : 0,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $pick->socket;
            }

            $spent += (float) $pick->price;
        }

        $selection = $this->ensurePowerAdequacy($selection, $pools);
        $spent = (float) collect($selection)->sum('price');

        return $this->decorateBuild(
            [
                'components' => $selection,
                'total' => $this->pricing->completePrice($spent),
                'remaining' => 0.0,
            ],
            0,
            'ideal',
            apuWarning: ! isset($selection['gpu']),
        );
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
     * The RAM generation a CPU socket implies (mainstream consumer sockets).
     * Returns null when the socket is unknown/unmapped so the pool is not
     * filtered (unknown sockets fall back to the existing compatibility rules).
     */
    protected function ramGenerationForSocket(?string $cpuSocket): ?string
    {
        $socket = strtoupper((string) $cpuSocket);

        return match (true) {
            $socket === '' => null,
            // AMD AM5, Intel Arrow Lake (LGA1851) — DDR5 only.
            str_contains($socket, 'AM5') || str_contains($socket, '1851') => 'DDR5',
            // AMD AM4 + Intel 12th/13th/14th gen (LGA1700) consumer ring — DDR4.
            str_contains($socket, 'AM4') || str_contains($socket, '1700')
                || str_contains($socket, '1151') || str_contains($socket, '1200')
                || str_contains($socket, '2066') => 'DDR4',
            default => null,
        };
    }

    /**
     * Whether a RAM component declares a current-generation memory type
     * (DDR4 or DDR5) anywhere in its name/specs. Used as the quality floor —
     * DDR3/DDR2 sticks are ancient and cannot be verified as compatible with
     * any modern platform, so they never get recommended.
     */
    protected function ramGenerationKnown(Component $ram): bool
    {
        return str_contains($this->ramGenerationHaystack($ram), 'DDR4')
            || str_contains($this->ramGenerationHaystack($ram), 'DDR5');
    }

    /**
     * Whether a RAM component's declared spec matches the CPU platform's
     * RAM generation. Generation is read from the `type` spec, the `speed`
     * spec (e.g. "DDR4-3200") or the catalogue name (e.g. "32GB DDR5 6000").
     * Any declared DDR-generation that differs from what the platform needs
     * is rejected (DDR3 vs DDR4 etc). A stick with no generation signal at
     * all stays eligible only if the quality floor has not already removed it
     * (better safe than picking an unverifiable part as the cheapest).
     */
    protected function ramGenerationMatches(Component $ram, string $generation): bool
    {
        $haystack = $this->ramGenerationHaystack($ram);

        if (str_contains($haystack, 'DDR4')) {
            return $generation === 'DDR4';
        }

        if (str_contains($haystack, 'DDR5')) {
            return $generation === 'DDR5';
        }

        if (str_contains($haystack, 'DDR')) {
            return false; // DDR3/DDR2/etc — never fits a modern platform.
        }

        return true;
    }

    /**
     * Combined name + spec text used for RAM generation detection.
     */
    protected function ramGenerationHaystack(Component $ram): string
    {
        $specs = $ram->specs ?? [];

        return strtoupper(implode(' ', [
            (string) ($specs['type'] ?? ''),
            (string) ($specs['speed'] ?? ''),
            (string) $ram->name,
        ]));
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

    /**
     * Whether a selection contains every category required for a working PC.
     *
     * @param  array<string, mixed>  $components
     */
    protected function isComplete(array $components, bool $allowMissingGpu = false): bool
    {
        $required = $this->requiredCategories;

        if ($allowMissingGpu) {
            $required = array_values(array_diff($required, ['gpu']));
        }

        return array_diff($required, array_keys($components)) === [];
    }

    /**
     * Whether a CPU carries built-in graphics (an APU / iGPU) — the hardware
     * the no-dedicated-GPU path depends on. Prefers explicit spec data and
     * falls back to catalogue naming conventions (AMD G-suffix APUs, Intel
     * non-F/Core-Pentium-Celeron desktop parts with HD/UHD/Iris graphics).
     */
    protected function hasIntegratedGraphics(Component $component): bool
    {
        $specs = $component->specs ?? [];

        $explicit = $specs['integrated_graphics'] ?? $specs['graphics'] ?? null;
        if ($explicit !== null) {
            return ! in_array(strtolower((string) $explicit), ['', '0', 'false', 'no', 'none'], true);
        }

        $name = strtoupper((string) $component->name);

        // AMD APUs — Ryzen G-series and Athlon G/GE parts carry Vega graphics.
        if (preg_match('/(RYZEN|ATHLON).*\b(?:[\d]+[A-Z]*G)\b/', $name) === 1) {
            return true;
        }

        // Intel — F/KF suffix means no graphics; everything else desktop has HD/UHD/Iris.
        if (str_contains($name, 'INTEL') || str_contains($name, 'CORE I') || str_contains($name, 'PENTIUM') || str_contains($name, 'CELERON')) {
            if (preg_match('/\bKF?\b/', $name) === 1) {
                return false;
            }

            // Legacy Pentium E-series desktop chips had no integrated graphics.
            if (preg_match('/PENTIUM E\d+/', $name) === 1) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Whether a selection relies on integrated graphics (no GPU part).
     *
     * @param  array<string, mixed>  $components
     */
    protected function hasApuComponents(array $components): bool
    {
        return ! isset($components['gpu']);
    }

    /**
     * Apply the quality floor per category so legacy/junk catalogue entries
     * never get recommended. Anything that cannot be verified against a
     * minimum sane spec is excluded (missing data is treated as sub-standard
     * for the parts where we know the minimum that modern systems require).
     *
     * @param  \Illuminate\Support\Collection<int, Component>  $pool
     * @return \Illuminate\Support\Collection<int, Component>
     */
    protected function viablePool(string $slug, $pool, ?string $cpuBrand = null)
    {
        return match ($slug) {
            'ram' => $pool->filter(fn (Component $component) =>
                $this->specCapacityGb($component) >= 16
                // No platform in the catalogue runs DDR3/DDR2. A stick that
                // declares a generation other than DDR4/DDR5 (or is ambiguous
                // because the name/specs are missing) cannot be verified as
                // current-gen, so it never ships in a complete build. This
                // kills ancient DDR3 "cheapest RAM" junk that previously made
                // physically impossible builds.
                && $this->ramGenerationKnown($component)
            ),
            'storage' => $pool->filter(fn (Component $component) => $this->specCapacityGb($component) >= 240),
            'psu' => $pool->filter(fn (Component $component) => $this->psuWattage($component) >= 400),
            'cpu' => $pool->filter(fn (Component $component) =>
                (int) ($component->specs['cores'] ?? 0) >= 4
                // A CPU with an unknown socket cannot be paired safely, so it
                // can never ship in a complete build. This kills legacy socket-
                // less junk (e.g. i5-2400) at the source for every pick path.
                && ! empty($this->canonicalSocket($component))
            ),
            'gpu' => $pool->filter(fn (Component $component) => (int) ($component->specs['memory'] ?? 0) >= 4),
            'motherboard' => $pool->filter(fn (Component $component) => ! empty($component->socket) || ! empty($component->chipset)),
            'cooler' => $cpuBrand === null || $cpuBrand === 'unknown'
                ? $pool
                : $pool->filter(fn (Component $component) => $this->coolerFitsBrand($component, $cpuBrand)),
            default => $pool,
        };
    }

    /**
     * Capacity in GB from a spec value like "32GB", "1024GB" or "1TB".
     */
    protected function specCapacityGb(Component $component): int
    {
        $raw = strtoupper((string) ($component->specs['capacity'] ?? '0'));

        if (str_contains($raw, 'TB')) {
            return (int) ((float) str_replace('TB', '', $raw) * 1000);
        }

        if (str_contains($raw, 'GB')) {
            return (int) str_replace('GB', '', $raw);
        }

        return (int) $raw;
    }

    /**
     * Wattage from a PSU: explicit spec, then "N W" in the name, then the
     * model number convention (PF400, A750GL, RM850e). Unknown returns 0 so
     * unverifiable parts pass the floor rather than being wrongly rejected.
     */
    protected function psuWattage(Component $component): int
    {
        $specs = $component->specs ?? [];

        $wattage = (int) ($specs['wattage'] ?? ($specs['power'] ?? 0));
        if ($wattage > 0) {
            return $wattage;
        }

        $name = strtoupper((string) $component->name);

        if (preg_match('/(\d{3,4})\s*W/i', $name, $m) === 1 || preg_match('/\b(\d{3,4})W\b/', $name, $m) === 1) {
            return (int) $m[1];
        }

        // Model-number conventions: PF400 = 400W, A750GL = 750W, RM850e = 850W.
        if (preg_match('/\b(?:P|A|RM|VP|CV|CX|VS|MAG|MWE)\s?(\d{3,4})\b/i', $name, $m2) === 1) {
            $value = (int) $m2[1];

            return ($value >= 300 && $value <= 1600) ? $value : 0;
        }

        return 0;
    }

    /**
     * Internal wattage estimate used to size the PSU safely:
     * explicit DB column first, then the GPU reference-TDP map (the catalogue
     * has no wattage for 99% of GPUs), then the PSU model-number parser.
     */
    protected function componentWattage(string $slug, Component $component): int
    {
        $explicit = (int) ($component->wattage ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        return match ($slug) {
            'gpu' => $this->gpuTdp((string) $component->name),
            'psu' => $this->psuWattage($component),
            default => 0,
        };
    }

    /**
     * Reference TDP (board power) for known dedicated GPUs, used to pick a PSU
     * with real headroom. Values are the published reference/typical figures
     * (e.g. RX 7900 XTX ≈ 355W, RTX 5090 ≈ 575W). Unknown models return 0 so
     * the power pass simply skips them rather than rejecting a build.
     */
    protected function gpuTdp(string $name): int
    {
        $h = strtoupper($name);

        $known = [
            '/\bRTX 5090\b/' => 575,
            '/\bRTX 5080\b/' => 360,
            '/\bRTX 5070 TI\b/' => 300,
            '/\bRTX 5070\b/' => 250,
            '/\bRTX 5060 TI\b/' => 180,
            '/\bRTX 5060\b/' => 145,
            '/\bRTX 4090\b/' => 450,
            '/\bRTX 4080 SUPER\b/' => 320,
            '/\bRTX 4080\b/' => 320,
            '/\bRTX 4070 TI SUPER\b/' => 285,
            '/\bRTX 4070 TI\b/' => 285,
            '/\bRTX 4070 SUPER\b/' => 220,
            '/\bRTX 4070\b/' => 200,
            '/\bRTX 4060 TI\b/' => 160,
            '/\bRTX 4060\b/' => 115,
            '/\bRTX 3090 TI\b/' => 450,
            '/\bRTX 3090\b/' => 350,
            '/\bRTX 3080 TI\b/' => 350,
            '/\bRTX 3080\b/' => 320,
            '/\bRTX 3070 TI\b/' => 290,
            '/\bRTX 3070\b/' => 220,
            '/\bRTX 3060 TI\b/' => 200,
            '/\bRTX 3060\b/' => 170,
            '/\bRTX 3050\b/' => 130,
            '/\bRTX 2080 TI\b/' => 250,
            '/\bRTX 2070 SUPER\b/' => 215,
            '/\bRTX 2070\b/' => 175,
            '/\bRTX 2060 SUPER\b/' => 175,
            '/\bRTX 2060\b/' => 160,
            '/\bRTX 1660 SUPER\b/' => 125,
            '/\bRTX 1660 TI\b/' => 120,
            '/\bRTX 1660\b/' => 120,
            '/\bGTX 1080 TI\b/' => 250,
            '/\bGTX 1080\b/' => 180,
            '/\bGTX 1070\b/' => 150,
            '/\bGTX 1060\b/' => 120,
            '/\bGTX 1050 TI\b/' => 75,
            '/\bRX 7900 XTX\b/' => 355,
            '/\bRX 7900 XT\b/' => 315,
            '/\bRX 7900 GRE\b/' => 260,
            '/\bRX 7800 XT\b/' => 263,
            '/\bRX 7700 XT\b/' => 245,
            '/\bRX 7600 XT\b/' => 148,
            '/\bRX 7600\b/' => 165,
            '/\bRX 6950 XT\b/' => 335,
            '/\bRX 6900 XT\b/' => 300,
            '/\bRX 6800 XT\b/' => 300,
            '/\bRX 6800\b/' => 250,
            '/\bRX 6750 XT\b/' => 250,
            '/\bRX 6700 XT\b/' => 230,
            '/\bRX 6600 XT\b/' => 160,
            '/\bRX 6600\b/' => 132,
            '/\bRX 5700 XT\b/' => 225,
            '/\bRX 5700\b/' => 180,
            '/\bRX 5600 XT\b/' => 160,
            '/\bRX 5500 XT\b/' => 130,
            '/\bRX VEGA 64\b/' => 295,
            '/\bRX VEGA 56\b/' => 210,
            '/\bRX 580\b/' => 185,
            '/\bRX 570\b/' => 150,
            '/\bARC B580\b/' => 190,
            '/\bARC A770\b/' => 225,
            '/\bARC A750\b/' => 225,
            '/\bARC A580\b/' => 185,
            '/\bARC A380\b/' => 75,
        ];

        foreach ($known as $pattern => $tdp) {
            if (preg_match($pattern, $h) === 1) {
                return $tdp;
            }
        }

        return 0;
    }

    /**
     * Guarantee every shipped build is physically powered: required wattage is
     * CPU draw + GPU draw + 200W headroom (same convention as the database's
     * `wattage_sufficient` rule). If the picked PSU is too small, upgrade to
     * the cheapest unit in the catalogue that can do the job. The upgrade may
     * push the build over budget — decorateBuild explains that honestly.
     *
     * @param  array<string, array<string, mixed>>  $selection
     * @param  array<string, \Illuminate\Support\Collection<int, Component>>  $pools
     * @return array<string, array<string, mixed>>
     */
    protected function ensurePowerAdequacy(array $selection, array $pools): array
    {
        $cpuW = (int) ($selection['cpu']['wattage'] ?? 0);
        $gpuW = (int) ($selection['gpu']['wattage'] ?? 0);

        // No wattage signal at all → nothing to enforce; every PSU in the
        // viable pool already passes the 400W floor.
        if ($cpuW === 0 && $gpuW === 0) {
            return $selection;
        }

        $need = $cpuW + $gpuW + 200;

        $psu = $selection['psu'] ?? null;
        $psuW = (int) ($psu['wattage'] ?? 0);

        if ($psu !== null && $psuW >= $need) {
            return $selection;
        }

        $pool = $pools['psu'] ?? collect();
        if ($pool->isEmpty()) {
            return $selection;
        }

        $better = $pool
            ->filter(fn (Component $component) => $this->psuWattage($component) >= $need)
            ->sortBy('price')
            ->first();

        if ($better === null) {
            // Nothing in the catalogue can safely power this GPU combo — keep
            // the original pick (decorateBuild's honest explanation covers it).
            return $selection;
        }

        $selection['psu'] = [
            'id' => $better->id,
            'name' => $better->name,
            'price' => (float) $better->price,
            'score' => $better->score,
            'wattage' => $this->psuWattage($better),
        ];

        return $selection;
    }

    /**
     * The TRUE socket for a CPU, derived from the model name rather than the
     * catalogue's `socket` column (which has proven unreliable — e.g. AMD
     * 3000/4000G/5000-series parts mislabelled as AM5). Knowing the real
     * socket is the foundation of every compatibility gate (motherboard,
     * cooler, RAM generation), so this must never trust dirty data when the
     * model number is recognisable.
     */
    protected function canonicalSocket(Component $component): ?string
    {
        $name = strtoupper((string) $component->name);
        $h = $name;

        // --- AMD desktop -------------------------------------------------
        if (preg_match('/(?:RYZEN|ATHLON)/', $h) === 1) {
            // Threadripper (TR4/sTRX4/sWRX8) — rare in consumer catalogue but
            // a recognisable AM-series exception; keep their own socket.
            if (preg_match('/THREADRIPPER/', $h) === 1) {
                return str_contains($h, 'AI MAX') ? null : ($component->socket ?: null);
            }

            // Ryzen-series model number "Ryzen X YYYY" where YYYY's first
            // digit is 1-5 => AM4 (incl. 5500, 5600G, 5700G, 3200G, 3600...).
            if (preg_match('/\bRYZEN \d[ ]?(\d{4})/', $h, $m) === 1) {
                $model = (int) $m[1];
                // 7000/8000G/9000 series are AM5; 1000-5000 series are AM4.
                return ($model >= 6000) ? 'AM5' : 'AM4';
            }

            // Athlon with a G-series / 200GE-3000G naming → AM4.
            if (preg_match('/\b(?:200GE|220GE|240GE|3000G|320GE|240GE)\b/', $h) === 1) {
                return 'AM4';
            }

            // Generic fallback — do not trust the dirty column for AMD.
            return null;
        }

        // --- Intel desktop -------------------------------------------------
        if (preg_match('/(?:CORE ULTRA|CORE I|PENTIUM|CELERON)/', $h) === 1) {
            // Arrow Lake: Core Ultra 5/7/9 2xx → LGA1851.
            if (preg_match('/CORE ULTRA \d \d{3}/', $h) === 1) {
                return 'LGA1851';
            }

            // Core i3/i5/i7/i9: 12th-14th gen (12xxx-14xxx) → LGA1700.
            if (preg_match('/CORE I\d[- ]?(\d{5})/', $h, $m) === 1) {
                $generation = (int) substr($m[1], 0, 2);
                if ($generation >= 12) {
                    return 'LGA1700';
                }
                if ($generation >= 10) {
                    return 'LGA1200';
                }
                if ($generation >= 6) {
                    return 'LGA1151';
                }
                return 'LGA1150';
            }

            // Pentium/Celeron with a G-number (G4400, G7400...) → follow the
            // same generation ladder by family number when recognisable.
            if (preg_match('/(?:PENTIUM|CELERON).*?\bG(\d{4})/', $h, $m) === 1) {
                $family = (int) $m[1];
                if ($family >= 6900) {
                    return 'LGA1700';
                }
                if ($family >= 5000) {
                    return 'LGA1200';
                }
                return 'LGA1151';
            }

            return $component->socket;
        }

        // Unknown / legacy — return the raw value so a human-readable socket
        // still appears, but the picker's viablePool() floor (require a
        // non-empty canonical socket here) already stops unknown-socket CPUs
        // from ever shipping in a complete build.
        return $component->socket;
    }

    /**
     * Rough CPU brand from a picked component (used for cooler sanity checks).
     */
    protected function cpuBrandOf(?array $cpu): string
    {
        $name = strtoupper((string) ($cpu['name'] ?? ''));

        if ($name === '') {
            return 'unknown';
        }

        if (str_contains($name, 'INTEL') || str_contains($name, 'CORE I') || str_contains($name, 'PENTIUM') || str_contains($name, 'CELERON')) {
            return 'intel';
        }

        return str_contains($name, 'AMD') ? 'amd' : 'unknown';
    }

    /**
     * Whether a cooler can pair with a CPU brand. Branded stock coolers
     * (AMD Wraith, Intel Laminar/stock) are restricted to their own brand;
     * aftermarket coolers fit anything.
     */
    protected function coolerFitsBrand(Component $cooler, string $cpuBrand): bool
    {
        $name = strtoupper((string) $cooler->name);

        $isIntelBranded = str_contains($name, 'LAMINAR') || (str_contains($name, 'INTEL') && ! str_contains($name, 'ARCTIC'));
        $isAmdBranded = str_contains($name, 'WRATH') || (str_contains($name, 'AMD') && ! str_contains($name, 'ARCTIC'));

        if ($isIntelBranded) {
            return $cpuBrand === 'intel';
        }

        if ($isAmdBranded) {
            return $cpuBrand === 'amd';
        }

        return true; // Aftermarket cooler — fits both CPUs.
    }
}
