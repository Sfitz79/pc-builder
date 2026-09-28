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
 *   complete_price = ( parts_cost + service_charge + delivery + min_margin ) / ( 1 - merchant_rate )
 *
 * So the margin on every build is at least £min_margin + merchant_rate% of the
 * complete price, while the customer-facing figure stays a single clean number.
 *
 * Delivery is inside that one number (Simon's directive 2026-09-28: "total
 * customer system price inc parts/del/build/service/margin as one cost"). It
 * used to be added on top at checkout, which meant the advertised system price
 * was not the price the customer actually paid. Set
 * pricing.include_delivery_in_system_price to false to restore the old
 * itemised behaviour.
 */
class BuildPricingService
{
    /**
     * Delivery cost folded into the system price (0 when billed separately).
     */
    public function foldedDelivery(): float
    {
        return (bool) config('pricing.include_delivery_in_system_price', true)
            ? (float) config('pricing.build_delivery', 0)
            : 0.0;
    }

    /**
     * Complete system price for a given parts cost. This is the single figure
     * the customer pays, including delivery.
     */
    public function completePrice(float|int|string $partsCost, float|int|string|null $serviceCharge = null): float
    {
        $parts = (float) $partsCost;
        $service = $serviceCharge ?? (float) config('pricing.service_charge', 0);
        $delivery = $this->foldedDelivery();
        $minMargin = (float) config('pricing.min_margin', 300);
        $merchantRate = (float) config('pricing.merchant_rate', 0.03);

        $denominator = max(1 - $merchantRate, 0.5);
        $total = ($parts + $service + $delivery + $minMargin) / $denominator;

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
     * Delivery is treated as a cost we carry, not as part of the margin base,
     * so it is excluded here when it is folded into the system price.
     */
    public function margin(float $complete, float|int|string $partsCost, float|int|string|null $serviceCharge = null): float
    {
        $service = $serviceCharge ?? (float) config('pricing.service_charge', 0);

        return round($complete - (float) $partsCost - $service - $this->foldedDelivery(), 2);
    }

    /**
     * Parts budget that keeps the COMPLETE price within a customer's system
     * budget. Works backwards from:
     *
     *   complete <= budget  =>  parts <= budget*(1-rate) - min_margin - service - delivery
     *
     * Delivery is subtracted because the customer's budget is now the single
     * all-in figure - if we did not reserve the delivery cost here, every build
     * would be quoted as if delivery were free and then overshoot by GBP 250.
     */
    public function partsBudgetFor(float $systemBudget): float
    {
        $budget = (float) $systemBudget;
        $minMargin = (float) config('pricing.min_margin', 300);
        $merchantRate = (float) config('pricing.merchant_rate', 0.03);
        $service = (float) config('pricing.service_charge', 0);
        $delivery = $this->foldedDelivery();

        return max(0.0, round($budget * (1 - $merchantRate) - $minMargin - $service - $delivery, 2));
    }
}