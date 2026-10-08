<?php

namespace App\Services;

/**
 * The three options the AI builder offers, per the brief:
 *
 *   1. UNDER BUDGET        the cheapest compliant machine, so there is always
 *                          something affordable on screen
 *   2. AT YOUR BUDGET      the closest total to the figure asked for, within
 *                          +/- GBP 150
 *   3. BEST FOR THE MONEY the highest performance-per-pound on offer
 *
 * Every candidate is produced by SystemAssembler, so all three obey
 * config/build_policy.php exactly as the featured range does.
 *
 * HONESTY NOTES, both deliberate:
 *
 * - "Performance" here is a RANKING PROXY, not a benchmark: the GPU's
 *   performance tier plus the CPU's core count. It is used only to order
 *   candidates against each other. No figure from it is ever published as a
 *   frame rate or a score, because we do not measure either.
 *
 * - If an option cannot be built inside the budget it is omitted rather than
 *   padded. An empty slot is honest; a filled one that quietly over budget is
 *   not.
 */
class BudgetOptions
{
    public function __construct(
        protected SystemAssembler $assembler,
        protected BuildPolicyGate $policy,
    ) {
    }

    /**
     * @return array<string,array> keyed by slot: under_budget, at_budget, best_value
     */
    public function options(float $budget, ?string $resolution, ?string $purpose = null): array
    {
        $taxonomy = (array) config('featured_builds');
        $resolution = $this->normaliseResolution($resolution);
        $tolerance = (float) ($taxonomy['ai_output']['budget_tolerance_gbp'] ?? 150);

        // Floor the GPU tier for this resolution from the taxonomy, and step it
        // by tier so "low" and "high" differ rather than producing the same
        // machine three times.
        $floors = (array) ($taxonomy['gpu_tier_floor'][$resolution] ?? ['low' => 2, 'mid' => 3, 'high' => 4]);

        $candidates = [];

        foreach (['low', 'mid', 'high'] as $tier) {
            // Scan a band around the ask rather than a single figure: the honest
            // machine for a budget is usually not priced at exactly that number.
            for ($pct = 100; $pct >= 55; $pct -= 5) {
                $ceiling = $budget * ($pct / 100);
                $result = $this->assembler->assemble([
                    'platform' => 'AM5',
                    'gpu_tier_floor' => (int) ($floors[$tier] ?? 2),
                    'budget_min' => 0,
                    'budget_max' => $ceiling,
                ]);

                if ($result['ok']) {
                    $candidates[] = $result + ['tier' => $tier, 'ceiling' => $ceiling];
                }

                // Two good candidates per tier is plenty; more only adds cost.
                if (count(array_filter($candidates, fn ($c) => $c['tier'] === $tier)) >= 2) {
                    break;
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        $price = fn (array $c) => (float) $c['total'];

        // 1. UNDER BUDGET: the cheapest candidate that genuinely sits below the
        //    ask. Falling back to the cheapest overall keeps a floor on screen
        //    rather than returning nothing.
        $under = collect($candidates)->filter(fn ($c) => $price($c) < $budget)->sortBy('total')->first();
        $under ??= collect($candidates)->sortBy('total')->first();

        // 2. AT YOUR BUDGET: closest to the ask, and only offered when it lands
        //    inside the tolerance band. A GBP 400 gap is not "at your budget".
        $at = collect($candidates)
            ->filter(fn ($c) => abs($price($c) - $budget) <= $tolerance)
            ->sortBy(fn ($c) => abs($price($c) - $budget))
            ->first();

        // 3. BEST FOR THE MONEY: highest proxy score per pound. Ranks against
        //    the other candidates only; never published as a benchmark.
        $best = collect($candidates)->sortByDesc(fn ($c) => $this->score($c) / max(1, $price($c)))->first();

        $out = [];
        foreach (['under_budget' => $under, 'at_budget' => $at, 'best_value' => $best] as $slot => $candidate) {
            if ($candidate === null) {
                continue;
            }
            $out[$slot] = $this->present($candidate, $budget);
        }

        return $out;
    }

    /**
     * Performance ranking proxy: GPU tier dominates, cores break ties.
     *
     * NOT a benchmark and never surfaced as one. It exists so "best for the
     * money" has a defensible definition instead of "the most expensive thing we
     * can fit".
     */
    protected function score(array $candidate): float
    {
        return ((float) $candidate['gpu_tier'] * 10) + ((float) $candidate['cores']);
    }

    protected function present(array $candidate, float $budget): array
    {
        $total = (float) $candidate['total'];

        return [
            'total' => round($total, 2),
            'remaining' => round($budget - $total, 2),
            'difference' => round($total - $budget, 2),
            'within_budget' => $total <= $budget,
            'tier' => $candidate['tier'],
            'cores' => $candidate['cores'],
            'gpu_tier' => $candidate['gpu_tier'],
            'integrated_graphics' => ! isset($candidate['parts']['GPU']),
            'components' => collect($candidate['parts'])->map(function ($p, $type) {
                return [
                    'type' => $type,
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => (float) $p->price,
                ];
            })->values()->all(),
        ];
    }

    protected function normaliseResolution(?string $resolution): string
    {
        $allowed = (array) config('featured_builds.axes.resolution', ['1080P', '1440P', '4K']);
        foreach ($allowed as $candidate) {
            if (strcasecmp((string) $candidate, (string) $resolution) === 0) {
                return (string) $candidate;
            }
        }

        return '1080P';
    }
}