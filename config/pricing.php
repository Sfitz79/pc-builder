<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PCTG Pricing
    |--------------------------------------------------------------------------
    |
    | Build & Delivery is a fixed fee applied before parts are selected. A
    | PayPal processing fee of 3% of the total price is added after parts are
    | selected. Both are hidden from the checkout UI by default and itemised
    | only on the confirmed invoice.
    |
    | Env: BUILD_DELIVERY_FEE, PAYPAL_FEE_RATE, PAYPAL_CURRENCY
    |
    */

    'build_delivery' => (float) env('BUILD_DELIVERY_FEE', 250),

    'paypal_fee_rate' => (float) env('PAYPAL_FEE_RATE', 0.03),

    'currency' => env('PAYPAL_CURRENCY', 'GBP'),

];
