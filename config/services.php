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

    /*
     * ScraperAPI - the cloud headless-browser lane for PCPartPicker.
     *
     * Why this exists: PCPartPicker sits behind a Cloudflare managed challenge
     * and returning the "Unavailable" page even to the local Byparr browser
     * when the host's egress is a VPN address. ScraperAPI's `render=true` runs
     * a cloud browser whose egress is NOT the local VPN, solves the challenge
     * server-side and returns the fully rendered HTML. Verified live
     * 2026-09-29: a video-card listing returned real GBP prices and a product
     * detail page returned the exact td__finalPrice / data-merchant-tag markup
     * this parser expects.
     *
     * Owner-supplied key. Leave empty to fall back to Byparr.
     */
    'scraperapi' => [
        'key' => env('SCRAPER_API_KEY'),
    ],

    /*
     * Awin product data feeds - the intended source of real merchant
     * price/stock EVIDENCE.
     *
     * Why this exists: the catalogue was scraped once and, as of 2026-09-29,
     * every price in it is unevidenced (0 of 2,708 rows carry a price_checked_at
     * stamp), which is the only reason PriceIntegrityService withholds nothing
     * yet - there is no truth to withhold it against. PCPartPicker cannot serve
     * as that evidence: it sits behind a Cloudflare managed challenge and now
     * returns the "Unavailable" page to the Byparr browser too.
     *
     * Two access paths, verified against Awin's developer docs on 2026-09-29:
     *
     *   Legacy product data feed (CSV):
     *     list     {feed_base}/datafeed/list/apikey/{feedApiKey}
     *     download {feed_base}/datafeed/download/apikey/{feedApiKey}/columns/.../format/csv/delimiter/%2C/compression/gzip/
     *     NOTE: the FEED api key is NOT the Publisher API key.
     *
     *   Enhanced feed (JSONL, Google format):
     *     {api_base}/publishers/{publisherId}/awinfeeds/download/{advertiserId}-retail-{locale}.jsonl
     *     with a Bearer token.
     *
     * Both require an Awin publisher account approved for the advertiser(s)
     * whose feeds we want. That account - and its keys - is an owner-held
     * credential (permission boundary), so these values are empty by default and
     * AwinFeedService reports "not configured" rather than inventing data.
     */
    'awin' => [
        'feed_base' => env('AWIN_FEED_BASE', 'https://productdata.awin.com'),
        'api_base' => env('AWIN_API_BASE', 'https://api.awin.com'),
        'feed_api_key' => env('AWIN_FEED_API_KEY'),
        'publisher_id' => env('AWIN_PUBLISHER_ID'),
        'api_token' => env('AWIN_API_TOKEN'),
        'locale' => env('AWIN_FEED_LOCALE', 'en_GB'),
        'timeout' => (int) env('AWIN_FEED_TIMEOUT', 300),
    ],

];
