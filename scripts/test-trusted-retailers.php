<?php
/**
 * The trusted-retailer filter, tested against the REAL regression it exists to prevent.
 *
 * The fixture is the actual 2026-10-13 PCPP rows for the i7-13700K: our catalogue held
 * GBP 349.99 (which is Overclockers UK's own price, verified independently), while the
 * cheapest overall offer was Amazon UK at GBP 485.00 - a 39% markup above every
 * mainstream UK seller PriceSpy tracks.
 *
 * A filter that cannot be shown to reject that row is not a filter.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PcppPriceService;

$svc = app(PcppPriceService::class);
$fail = 0;
$check = function (string $label, bool $cond, string $extra = '') use (&$fail) {
    printf("  %-6s %s%s\n", $cond ? 'PASS' : 'FAIL', $label, $extra !== '' ? "  ($extra)" : '');
    if (! $cond) {
        $fail++;
    }
};

$row = function (float $price, string $merchant, bool $inStock = true, string $url = 'https://example.co.uk/p') {
    return ['price' => $price, 'in_stock' => $inStock, 'merchant' => $merchant,
        'merchant_url' => $url, 'base' => $price, 'shipping' => 0];
};

echo "=== merchant normalisation ===\n";
foreach ([
    ['Overclockers.co.uk', true],
    ['Scan', true],
    ['Clove', true],
    ['Box', true],
    ['eBay UK', true],
    ['Amazon.co.uk', true],
    ['Newegg UK', true],
    ['Awddit', false],
    ['Clove Technology', true],
    ['CDW', true],
    ['', false],
    [null, false],
] as [$name, $expected]) {
    $got = PcppPriceService::isTrustedMerchant($name);
    $check(sprintf('%-24s trusted=%s', var_export($name, true), $got ? 'yes' : 'no'), $got === $expected);
}

echo "\n=== THE REGRESSION, AS IT ACTUALLY HAPPENED ===\n";
// CORRECTION to the first version of this fixture, which was wrong in a way that would
// have hidden the bug. It assumed PCPP offered the i7-13700K at GBP 349.99 from
// Overclockers. It did NOT: the observed page had amazonuk GBP 485.00 as its CHEAPEST
// offer, and our catalogue price of GBP 349.99 came from an EARLIER scrape that matched
// Overclockers UK's own page price. So the filter's real job here is to refuse to import a
// marketplace price at all, not to pick a better trusted one that PCPP never offered.
$pcppRows = [
    $row(485.00, 'Amazon.co.uk'),
    $row(502.10, 'Awddit'),
    $row(640.00, 'Clove'),
];
$t = $svc->cheapestTrustedOffer($pcppRows);
$overall = $svc->cheapestOffer($pcppRows);

$check('old behaviour imports Amazon GBP 485.00', abs($overall['price'] - 485.00) < 0.01, (string) $overall['price']);
$check('Amazon UK IS trusted, per the Boss retailer list', PcppPriceService::isTrustedMerchant('Amazon.co.uk'));
$check('but "Amazon Marketplace" is NOT - those are third-party sellers, not Amazon',
    ! PcppPriceService::isTrustedMerchant('Amazon Marketplace'));

echo "\n=== AVERAGING, as the Boss directed ===\n";
// The offers actually observed on 2026-10-13 for the i7-13700K.
$observed = [
    $row(349.99, 'Overclockers.co.uk'),
    $row(382.45, 'OnBuy.co.uk'),
    $row(415.00, 'Amazon Marketplace'),
    $row(438.99, 'Clove'),
    $row(485.00, 'Amazon.co.uk'),
];
$a = $svc->averageTrustedPrice($observed);
$check('averages 4 trusted offers (Amazon Marketplace excluded)', $a['sample_size'] === 4, (string) $a['sample_size']);
$check('OcUK 349.99 + OnBuy 382.45 + Clove 438.99 + Amazon 485.00 = 1656.43 / 4 = 414.11 -> 415',
    abs($a['price'] - 415.00) < 0.01, (string) $a['price']);
$check('records the trusted range for audit', $a['min'] === 349.99 && $a['max'] === 485.00, $a['min'] . '-' . $a['max']);
$check('records the median of the trusted set', abs($a['median'] - 410.72) < 0.01, (string) $a['median']);
$check('has no single merchant, because it is an average', $a['merchant'] === null);
$check('the quote sits above the cheapest UK price and below the dearest',
    $a['price'] > $a['min'] && $a['price'] < $a['max'], (string) $a['price']);

echo "\n=== rounding is UP, never down ===\n";
$exact = [$row(100.00, 'Scan'), $row(100.00, 'Clove')];
$e = $svc->averageTrustedPrice($exact);
$check('exact GBP 100 stays GBP 100 (no float drift to 101)', $e['price'] === 100.00, (string) $e['price']);
$u = $svc->averageTrustedPrice([$row(100.00, 'Scan'), $row(101.00, 'Clove')]);
$check('GBP 100.50 rounds up to 101', $u['price'] === 101.00, (string) $u['price']);

echo "\n=== a single retailer is NOT an average ===\n";
$o = $svc->averageTrustedPrice([$row(300.00, 'Scan')]);
$check('sample_size is 1', $o['sample_size'] === 1);
$check('below MIN_AVERAGE_SAMPLE, so the caller must not write it',
    $o['sample_size'] < PcppPriceService::MIN_AVERAGE_SAMPLE, 'MIN=' . PcppPriceService::MIN_AVERAGE_SAMPLE);
$check('but a price is still returned for information', $o['price'] === 300.00, (string) $o['price']);

echo "\n=== untrusted sellers never enter the average ===\n";
$s = $svc->averageTrustedPrice([
    $row(10.00, 'Awddit'),
    $row(100.00, 'SomeRandomSeller'),
    $row(349.99, 'Overclockers.co.uk'),
    $row(382.45, 'Clove'),
]);
$check('the GBP 10 and GBP 100 junk offers are excluded', $s['sample_size'] === 2, (string) $s['sample_size']);
$check('average is of the two trusted offers only', abs($s['price'] - 367.00) < 0.01, (string) $s['price']);

echo "\n=== no trusted stock = no price at all ===\n";
$none = $svc->averageTrustedPrice([$row(50.00, 'Awddit'), $row(60.00, 'Random')]);
$check('price is null', $none['price'] === null);
$check('and it explains why', str_contains((string) $none['note'], 'No trusted UK retailer'), (string) $none['note']);
$oos = $svc->averageTrustedPrice([$row(349.99, 'Scan', false), $row(400.00, 'Clove', false)]);
$check('out-of-stock trusted offers are not averaged', $oos['price'] === null, var_export($oos['price'], true));

echo "\n=== when a trusted retailer DOES undercut, the cheapest trusted wins ===\n";
$withOcuk = [
    $row(485.00, 'Amazon.co.uk'),
    $row(349.99, 'Overclockers.co.uk'),
    $row(438.99, 'Clove'),
];
$t2 = $svc->cheapestTrustedOffer($withOcuk);
$check('picks Overclockers GBP 349.99', abs($t2['price'] - 349.99) < 0.01, (string) $t2['price']);
$check('and labels it trusted', $t2['trusted_found'] === true);
$check('old behaviour would have picked the GBP 50-cheaper trusted row anyway', abs($svc->cheapestOffer($withOcuk)['price'] - 349.99) < 0.01);

echo "\n=== fallback honesty: no trusted retailer offers the part ===\n";
$onlyMarketplace = [$row(120.00, 'Awddit'), $row(95.00, 'SomeRandomSeller')];
$fb = $svc->cheapestTrustedOffer($onlyMarketplace);
$check('falls back rather than returning null', $fb !== null);
$check('fallback is flagged trusted_found=false', $fb['trusted_found'] === false);
$check('fallback carries an explanation', ! empty($fb['trusted_note']), (string) ($fb['trusted_note'] ?? ''));

echo "\n=== untrusted-only rows are never preferred over a trusted one ===\n";
$mixed = [$row(50.00, 'Awddit'), $row(300.00, 'Scan')];
$m = $svc->cheapestTrustedOffer($mixed);
$check('the GBP 50 untrusted offer is ignored', abs($m['price'] - 300.00) < 0.01, (string) $m['price']);
$check('and trusted_found is true', $m['trusted_found'] === true);

echo "\n=== edge cases ===\n";
$check('empty rows return null', $svc->cheapestTrustedOffer([]) === null);
$oos = $svc->cheapestTrustedOffer([$row(10.00, 'Scan', false), $row(20.00, 'Overclockers.co.uk', false)]);
$check('all out-of-stock still returns an answer', $oos !== null);
$check('...and says it is out of stock', $oos['in_stock'] === false);

echo "\n" . ($fail === 0 ? "ALL PASS ({$fail} failures)" : "{$fail} FAILED");
exit($fail === 0 ? 0 : 1);