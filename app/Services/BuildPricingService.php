<?php

namespace App\Services;

use App\Models\Build;

/**
 * The PCTG complete-price engine.
 *
 * Pricing model (Simon's mandate, 2026-09-08): clients see ONE complete price
 * for a system — never a per-part breakdown. The hidden margin covers build,
 * testing and warranty costs plus merchant processing:
 *
 *   complete_price = ( parts_cost + service_charge + min_margin ) / ( 1 - merchant_rate )
 *
 * So the margin on every build is at least £min_margin + merchant_rate% of the
 * complete price, while the customer-facing figure stays a single clean number.
 */
class BuildPricingService
{
    /**
     * Complete system price for a given parts cost (the price the client pays
     * for the system itself, before delivery).
     */
    public function completePrice(float|int|string $partsCost, float|int|string|null $serviceCharge = null): float
    {
        $parts = (float) $partsCost;
        $service = $serviceCharge ?? (float) config('pricing.service_charge', 0);
        $minMargin = (float) config('pricing.min_margin', 300);
        $merchantRate = (float) config('pricing.merchant_rate', 0.03);

        $denominator = max(1 - $merchantRate, 0.5);
        $total = ($parts + $service + $minMargin) / $denominator;

        return round($total, 2);
    }

    /**
     * Complete price for a saved build, using its snapshot part prices.
     */
    public function fromBuild(Build $build): float
    {
        return $this->completePrice($build->build_cost);
    }

    /**
     * The hidden margin for a complete price / parts cost pair.
     * Ensures the margin is at least min_margin + merchant_rate% of complete.
     */
    public function margin(float $complete, float|int|string $partsCost, float|int|string|null $serviceCharge = null): float
    {
        $service = $serviceCharge ?? (float) config('pricing.service_charge', 0);

        return round($complete - (float) $partsCost - $service, 2);
    }

    /**
     * Parts budget that keeps the COMPLETE price within a customer's system
     * budget. Works backwards from:
     *
     *   complete <= budget  =>  parts <= budget*(1-rate) - min_margin - service
     */
    public function partsBudgetFor(float $systemBudget): float
    {
        $budget = (float) $systemBudget;
        $minMargin = (float) config('pricing.min_margin', 300);
        $merchantRate = (float) config('pricing.merchant_rate', 0.03);
        $service = (float) config('pricing.service_charge', 0);

        return max(0.0, round($budget * (1 - $merchantRate) - $minMargin - $service, 2));
    }
}