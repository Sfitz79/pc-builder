<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Metenzi — license key / digital goods fulfilment
    |--------------------------------------------------------------------------
    |
    | Credentials come from the Metenzi Seller Portal → API Access. The API
    | key is required; the signing secret is only needed when HMAC signing is
    | enabled on the account. The webhook secret is returned when registering
    | a webhook and must be stored verbatim (including the whsec_ prefix).
    |
    */

    'base_url' => env('METENZI_BASE_URL', 'https://metenzi.com/api/public'),

    'api_key' => env('METENZI_API_KEY'),

    'signing_secret' => env('METENZI_SIGNING_SECRET'),

    'webhook_secret' => env('METENZI_WEBHOOK_SECRET'),

    /*
    | EUR → GBP conversion used to price software products for the store.
    | Metenzi invoices in EUR; customers pay in GBP through PayPal.
    */
    'gbp_rate' => (float) env('METENZI_GBP_RATE', 0.85),

    'currency' => 'GBP',

];
