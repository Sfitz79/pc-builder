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
 *   complete_price = ( parts_cost + service_charge + delivery )
 *                    * ( 1 + margin_rate ) / ( 1 - merchant_rate )
 *
 * So the customer pays parts + build & delivery + a percentage margin, grossed
 * up to cover the payment processor, while the customer-facing figure stays a
 * single clean number.
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
        $marginRate = (float) config('pricing.margin_rate', 0.05);
        $merchantRate = (float) config('pricing.merchant_rate', 0.03);

        $denominator = max(1 - $merchantRate, 0.5);

        // Cost base = parts + build & delivery, then a percentage margin, then
        // the payment-processor fee grossed into the single customer price.
        $base = $parts + $service + $delivery;
        $total = $base * (1 + $marginRate) / $denominator;

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
     * This is what the customer pays above parts + build & delivery: the
     * percentage margin plus the grossed-up payment-processor fee.
     */
    public function margin(float $complete, float|int|string $partsCost, float|int|string|null $serviceCharge = null): float
    {
        $service = $serviceCharge ?? (float) config('pricing.service_charge', 0);

        return round($complete - (float) $partsCost - $service - $this->foldedDelivery(), 2);
    }

    /**
     * Parts budget that keeps the COMPLETE price within a customer's system
     * budget. Inverts completePrice:
     *
     *   complete = (parts + service + delivery) * (1+margin) / (1-rate)
     *   => parts = budget*(1-rate)/(1+margin) - service - delivery
     *
     * Both the build & delivery cost and the grossed-up processor fee are
     * reserved out of the customer's all-in budget - if they were not, every
     * build would be quoted as if delivery and margin were free and overshoot.
     */
    public function partsBudgetFor(float $systemBudget): float
    {
        $budget = (float) $systemBudget;
        $marginRate = (float) config('pricing.margin_rate', 0.05);
        $merchantRate = (float) config('pricing.merchant_rate', 0.03);
        $service = (float) config('pricing.service_charge', 0);
        $delivery = $this->foldedDelivery();

        return max(0.0, round(
            $budget * (1 - $merchantRate) / (1 + $marginRate) - $service - $delivery,
            2
        ));
    }
}