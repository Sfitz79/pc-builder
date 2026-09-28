<?php
// Genie 2026-09-28: how far did the merchant-confirming runs actually get, and
// what is the breakdown of the remaining stale rows?
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\PriceIntegrityService;

$integrity = app(PriceIntegrityService::class);
$quarantined = $integrity->quarantinedIds();

$totalActive = Component::where('active', 1)->count();
$verifiedToday = Component::where('active', 1)
    ->whereNotNull('price_checked_at')
    ->where('price_checked_at', '>=', now()->subHours(8))
    ->count();

$stale = Component::where('active', 1)
    ->where(function ($q) {
        $q->whereNull('price_checked_at')
            ->orWhere('price_checked_at', '<', now()->subDays(PriceIntegrityService::FRESH_DAYS)->toDateTimeString());
    })
    ->get();

printf("active rows                     : %d\n", $totalActive);
printf("merchant-confirmed in last 8h  : %d\n", $verifiedToday);
printf("still stale                    : %d\n\n", $stale->count());

$noUrl = 0;
$pcpp = 0;
$other = 0;
$pcppQuarantined = 0;
foreach ($stale as $row) {
    $source = trim((string) $row->source_url);
    if ($source === '') {
        $noUrl++;
    } elseif (preg_match('#^https?://uk\.pcpartpicker\.com/product/#i', $source)) {
        $pcpp++;
        if (isset($quarantined[(int) $row->id])) {
            $pcppQuarantined++;
        }
    } else {
        $other++;
    }
}

printf("  no source_url (placeholders) : %d\n", $noUrl);
printf("  PCPP page, refreshable       : %d  (quarantined %d)\n", $pcpp, $pcppQuarantined);
printf("  some other source            : %d\n", $other);

echo "\n--- sample of merchant-confirmed rows (do they carry a retailer URL?) ---\n";
foreach (Component::where('active', 1)
    ->where('price_checked_at', '>=', now()->subHours(8))
    ->limit(8)
    ->get() as $row) {
    $host = parse_url((string) $row->source_url, PHP_URL_HOST) ?: '(none)';
    printf("  [%d] GBP %8.2f  %-24s %s\n", $row->id, $row->price, $host, mb_substr((string) $row->name, 0, 42));
}
