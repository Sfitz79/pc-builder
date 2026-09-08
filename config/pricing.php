<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PCTG Pricing
    |--------------------------------------------------------------------------
    |
    | Pricing model (Simon's mandate, 2026-09-08):
    |
    |   complete_price = ( parts_cost + service_charge + min_margin ) / ( 1 - merchant_rate )
    |
    | The margin (build/test/warranty + merchant costs) is HIDDEN from clients.
    | Customers see ONE complete price for the system - never a parts breakdown.
    |
    | Env: BUILD_DELIVERY_FEE, PAYPAL_FEE_RATE, PCTG_MIN_MARGIN,
    |      PCTG_MERCHANT_RATE, PCTG_SERVICE_CHARGE, PAYPAL_CURRENCY
    |
    */

    // Fixed delivery fee, kept as a separate visible line at checkout.
    'build_delivery' => (float) env('BUILD_DELIVERY_FEE', 250),

    // Merchant processing rate covered by the hidden margin (not billed to the
    // customer as an itemised fee).
    'merchant_rate' => (float) env('PCTG_MERCHANT_RATE', 0.03),

    // Minimum build/test/warranty margin added to every system, hidden.
    'min_margin' => (float) env('PCTG_MIN_MARGIN', 300),

    // Optional service charge line included in the margin base (default 0 —
    // build/test/warranty is covered entirely by the margin).
    'service_charge' => (float) env('PCTG_SERVICE_CHARGE', 0),

    'currency' => env('PAYPAL_CURRENCY', 'GBP'),

];