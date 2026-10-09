<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PCTG Pricing
    |--------------------------------------------------------------------------
    |
    | Pricing model (Simon's mandate, 2026-09-08):
    |
    |   complete_price = ( parts_cost + service_charge + delivery )
    |                    * ( 1 + margin_rate ) / ( 1 - merchant_rate )
    |
    | The margin (build/test/warranty + merchant costs) is HIDDEN from clients.
    | Customers see ONE complete price for the system - never a parts breakdown.
    |
    | Env: BUILD_DELIVERY_FEE, PCTG_MARGIN_RATE, PCTG_MERCHANT_RATE,
    |      PCTG_SERVICE_CHARGE, PAYPAL_CURRENCY
    |
    */

    // Fixed delivery fee.
    //
    // Simon's directive 2026-09-28: the customer sees ONE all-in cost for a
    // system - "total customer system price inc parts/del/build/service/margin
    // as one cost". Delivery is therefore folded INTO the system price rather
    // than added as a separate line at checkout. The old behaviour billed
    // GBP 785.85 for a system and then added GBP 250 on top, which is exactly
    // the itemised breakdown the directive removes.
    //
    // The value is still needed: it is part of what we pay for, so it belongs in
    // the margin base. Keep the key (orders store it historically) - just do not
    // add it on top when the flag below is on.
    'build_delivery' => (float) env('BUILD_DELIVERY_FEE', 250),

    // Fold delivery into the single system price rather than billing it as a
    // separate line. Set false to restore the old itemised behaviour.
    'include_delivery_in_system_price' => (bool) env('PCTG_INCLUDE_DELIVERY', true),

    // Merchant processing rate covered by the hidden margin (not billed to the
    // customer as an itemised fee).
    'merchant_rate' => (float) env('PCTG_MERCHANT_RATE', 0.03),

    // Percentage margin applied to the cost base (parts + build & delivery).
    //
    // Simon's model, 2026-10-09: "parts + build & del (GBP 250) + margin
    // + 3% of total for PayPal fees = total customer cost". Margin set to 5%.
    //
    //   total = ( parts + 250 ) * 1.05 / 0.97
    'margin_rate' => (float) env('PCTG_MARGIN_RATE', 0.05),

    // Legacy flat margin. Retained so an old env var cannot silently re-add it;
    // the percentage margin above supersedes it. Keep at 0.
    'min_margin' => (float) env('PCTG_MIN_MARGIN', 0),

    // Optional service charge line included in the margin base (default 0 —
    // build/test/warranty is covered entirely by the margin).
    'service_charge' => (float) env('PCTG_SERVICE_CHARGE', 0),

    /*
    | How far over a customer's stated budget a build may be and still be
    | published as a match for that budget.
    |
    | Measured 2026-09-28 against the live, refreshed catalogue: the recommender
    | returned GBP 1,479 for a GBP 1,000 ask (48% over) and GBP 1,580 for a
    | GBP 1,200 ask (32% over), and the publish gate waved both through because
    | it only ever checked price FRESHNESS, never the ceiling the customer gave
    | us. Quoting someone half again what they asked for is the fastest way to
    | lose a sale and a 5-star review, so the gate now refuses it.
    |
    | The tolerance exists so that ordinary rounding and "roughly a thousand"
    | requests are not treated as failures. 5% is comfortably inside that.
    */
    'budget_ceiling_tolerance' => (float) env('PCTG_BUDGET_CEILING_TOLERANCE', 0.05),

    'currency' => env('PAYPAL_CURRENCY', 'GBP'),

];