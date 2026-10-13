<?php

/**
 * Feed REAL PCPP product-page HTML through the REAL offer extractor.
 *
 * WHY
 * ---
 * `components:refresh-prices --dry-run` reported "No merchant could be confirmed buyable.
 * Tried 0 offer(s)" for every GPU sampled on 2026-10-13. Byparr was healthy (health 200 and a
 * 188 KB / 200 response for the same page), so the failure was inside our own parsing - or the
 * offers list was genuinely empty.
 *
 * Tests using canned fixture HTML cannot answer that, because a fixture stays parseable long
 * after the live page stops matching. So this fetches once and runs the live extractor.
 *
 * READ-ONLY: fetches and parses, writes nothing.
 *
 * Usage: php scripts/genie-probe-pcpp-offers.php [source_url]
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PcppPriceService;
use Illuminate\Support\Facades\DB;

$svc = app(PcppPriceService::class);

$url = $argv[1] ?? null;
if (! $url) {
    $url = DB::table('components')
        ->where('active', true)
        ->where('source_url', 'like', '%pcpartpicker.com/product/%')
        ->orderByDesc('id')
        ->value('source_url');
}

echo 'SOURCE: '.$url."\n\n";

$html = fetchViaByparr($url);

if ($html === null) {
    echo "RESULT: the page could not be fetched at all - the failure is upstream of the parser.\n";
    exit(1);
}

echo 'FETCHED: '.strlen($html)." bytes\n";

// What does the extractor actually see?
$rows = preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $m);
echo '  <tr> rows found      : '.count($m[1] ?? [])."\n";
echo '  rows w/ td__finalPrice: '.(int) count(array_filter($m[1] ?? [], fn ($r) => stripos($r, 'td__finalPrice') !== false))."\n";
echo '  rows w/ th__merchant  : '.(int) count(array_filter($m[1] ?? [], fn ($r) => stripos($r, 'th__merchant') !== false))."\n";
echo '  data-merchant-tag     : '.substr_count($html, 'data-merchant-tag')."\n";
echo '  GBP/£ price strings   : '.substr_count($html, '£')."\n\n";

$offers = $svc->extractRows($html);

echo 'OFFERS EXTRACTED: '.count($offers)."\n";
foreach (array_slice($offers, 0, 8) as $i => $o) {
    printf(
        "  %d. GBP %-9.2f in_stock=%-5s merchant=%-22s\n",
        $i + 1,
        $o['price'] ?? 0,
        ! empty($o['in_stock']) ? 'yes' : 'NO',
        $o['merchant'] ?? '(none)'
    );
}

if (count($offers) < 2) {
    echo "\n=> Fewer than 2 offers means the averaging rule (which requires at least two\n";
    echo "   trusted offers) cannot produce a price. This is the live blocker.\n";
}

function fetchViaByparr(string $url): ?string
{
    // NOTE: services.byparr.url ALREADY ends in "/v1" (measured 2026-10-13). Appending
    // another "/v1" hits /v1/v1 and returns 404 - which looks exactly like "the retailer
    // blocked us" and is not. The configured value is used verbatim.
    $byparr = rtrim((string) config('services.byparr.url', 'http://localhost:8191/v1'), '/');
    echo 'Byparr endpoint: '.$byparr."\n";

    $res = Illuminate\Support\Facades\Http::timeout(120)->post($byparr, [
        'url' => $url,
        'type' => 'html',
    ]);

    if (! $res->successful()) {
        echo 'Byparr HTTP '.$res->status()."\n";

        return null;
    }

    $body = $res->json();
    $html = $body['solution']['response'] ?? null;

    if (! is_string($html) || $html === '') {
        echo "Byparr returned no solution.response (top-level keys: "
            .implode(', ', array_keys($body)).")\n";

        return null;
    }
    echo 'Byparr solution.status: '.($body['solution']['status'] ?? '?')."\n";

    return $html;
}
