<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Withhold unverified prices from customer-facing build pools?
    |---------------------------------------------------------------------------
    |
    | The boss directive (2026-09-27) is that every price we publish must be for
    | live available stock. PriceIntegrityService is the mechanism for that, and
    | it measures a component's freshness from the `price_checked_at` column,
    | which is only written when a real merchant page confirms the part is
    | buyable (see PcppPriceService::confirmAtMerchant and
    | PriceIntegrityService::markChecked).
    |
    | This flag is FALSE by default, and the reason is a measured fact rather
    | than caution. On 2026-09-28, immediately after the price_checked_at
    | migration was first applied to live Neon, the check returned:
    |
    |     clean() publishable : 0 of 2708
    |     stale                : 2708
    |     unverified           : 0
    |     workableBands()     : floor GBP 0.00 for 1080p, 1440p and 4K
    |
    | With this on, that state means the configurator offers the customer an
    | empty parts list and every band floor collapses to zero. It is not a
    | quality gate, it is a total outage, and it would fire on the very deploy
    | intended to improve the site.
    |
    | The underlying problem is sourcing, not code: the live catalogue has never
    | been verified against a merchant, and PCPP's own availability cells are
    | too often empty to serve as evidence while Amazon serves anti-bot
    | interstitials to automated clients. Until a trustworthy feed (Awin or a
    | retailer stock feed) is feeding evidence at good coverage, keep this off
    | and treat the gate as a REPORTING tool.
    |
    | Turn it on deliberately, with the coverage numbers in hand:
    |
    |     php artisan tinker
    |     >>> app(App\Services\PriceIntegrityService::class)->buildFreshness(
    |     ...     App\Models\Component::where('active', true)->get()->toArray());
    |
    | `stale` should be a small minority, not the whole catalogue. Corrupt and
    | quarantined rows are rejected either way, so the gate is still removing
    | known-bad prices while this is off.
    |
    */

    'enforce_freshness' => (bool) env('PRICE_INTEGRITY_ENFORCE_FRESHNESS', false),

];
