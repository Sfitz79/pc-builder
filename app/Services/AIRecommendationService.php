<?php

namespace App\Services;

use App\Models\AiRecommendation;
use App\Models\Category;
use App\Models\Component;
use Illuminate\Support\Collection;

class AIRecommendationService
{
    public function __construct(protected GeminiService $gemini)
    {
    }

    /**
     * Pick the best value component per category within a budget.
     *
     * When GEMINI_API_KEY is configured, the heuristic picks are refined with
     * Gemini-generated per-category weights and a build rationale is attached
     * to the result under the `ai` key. Any Gemini failure (or a missing key)
     * returns the heuristic build unchanged.
     *
     * @return array{components: array<string, array{id: int, name: string, price: float, score: int}>, total: float, remaining: float, ai?: array<string, mixed>}
     */
    public function recommend(float $budget, ?string $purpose = null, ?string $resolution = null, ?int $userId = null): array
    {
        $categories = Category::active()->ordered()->get();
        $pools = [];

        foreach ($categories as $category) {
            $pools[$category->slug] = Component::active()
                ->where('category_id', $category->id)
                ->where('stock', '>', 0)
                ->get()
                ->each(fn (Component $component) => $component->score = $this->scoreComponent($component, $category, $purpose, $resolution));
        }

        // Optional Gemini weighting pass. Bounded and additive; never blocks the build.
        $ai = $this->gemini->scoreWeights($pools, $budget, $purpose, $resolution);

        if ($ai !== null) {
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

        $selection = [];
        $spent = 0.0;
        $slack = 0.0;
        $cpuSocket = null;
        $hardCap = $budget * 0.95;

        // Per-category budget shares so the greedy pick cannot run away from
        // the user's chosen budget the way a flat running-total check does.
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

            if ($slug === 'motherboard' && $cpuSocket !== null) {
                $socketMatch = $candidates
                    ->filter(fn (Component $component) => $component->socket === $cpuSocket)
                    ->sortByDesc('score');

                if ($socketMatch->isNotEmpty()) {
                    $candidates = $socketMatch;
                }
            }

            $pick = $candidates->sortByDesc('score')->first();

            if ($pick === null) {
                // Nothing fits this category within the share — try anything
                // that still fits the overall budget before giving up on it.
                $pick = $pool
                    ->filter(fn (Component $component) => ($spent + (float) $component->price) <= $hardCap)
                    ->sortByDesc('score')
                    ->first();
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
            $slack = max(0.0, $allow - (float) $pick->price);
        }

        if ($userId !== null) {
            AiRecommendation::create([
                'user_id' => $userId,
                'budget' => $budget,
                'purpose' => $purpose,
                'resolution' => $resolution,
                'recommendation' => $selection,
            ]);
        }

        $result = [
            'components' => $selection,
            'total' => round($spent, 2),
            'remaining' => round(max(0, $budget - $spent), 2),
        ];

        if ($ai !== null) {
            $result['ai'] = [
                'provider' => 'gemini',
                'model' => config('gemini.model'),
                'rationale' => $ai['rationale'] ?? null,
            ];
        }

        return $result;
    }

    protected function scoreComponent(Component $component, Category $category, ?string $purpose, ?string $resolution): int
    {
        $score = 0;

        $specs = $component->specs ?? [];
        $price = (float) $component->price;

        $score += match ($category->slug) {
            'cpu' => $this->scoreCpu($specs, $purpose),
            'gpu' => $this->scoreGpu($specs, $purpose, $resolution),
            'ram' => (int) ($specs['capacity'] ?? 0) >= 64 ? 80 : 60,
            'storage' => (int) str_replace('TB', '', $specs['capacity'] ?? $component->name) >= 2 ? 80 : 60,
            default => 60,
        };

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
}
