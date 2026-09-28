<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'currency' => env('PAYPAL_CURRENCY', 'GBP'),
    ],

    /*
     * Byparr is the anti-bot proxy the live price pass runs through.
     * PCPartPicker sits behind a Cloudflare managed challenge, so a plain HTTP
     * client never sees the price table. Launch it with
     * scripts\launch-byparr.ps1 before running components:refresh-prices.
     *
     * Do not run this pass over a VPN: a VPN address is precisely the profile
     * Cloudflare challenges, and every item times out until the VPN is off.
     */
    'byparr' => [
        'url' => env('BYPARR_URL', 'http://localhost:8191/v1'),
    ],

];
