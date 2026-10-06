<?php
// Genie 2026-09-28: prove the Byparr -> PCPP image chain on a handful of rows
// before running it across the catalogue.
//
// The idea: every active component already carries a PCPartPicker source_url,
// and Byparr renders pages that block plain HTTP. So we can recover product
// photography for rows the local cache cannot reach (the name+price join only
// resolved 639 of 2,708).
//
// This is a PROBE. It fetches a few pages, extracts the image URL, downloads it
// next to the existing cache and reports. It does not write image_url and it
// does not deploy anything - a bulk run needs the extraction proven first.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$byparr = 'http://127.0.0.1:8191/v1';
$outDir = public_path('img/components');
$limit = (int) ($argv[1] ?? 3);

// Rows with a PCPP handle. Prefer ones the local cache cannot serve, since
// those are the gap we actually care about.
$rows = DB::table('components')
    ->where('active', true)->whereNotNull('source_url')->where('source_url', 'LIKE', '%pcpartpicker%')
    ->orderBy('id')->limit(40)->get(['id', 'name', 'source_url', 'price']);

if ($rows->isEmpty()) {
    echo "no PCPP source_url rows".PHP_EOL;
    exit(0);
}

$localIds = [];
foreach (scandir($outDir) ?: [] as $f) {
    if (preg_match('/^(\d+)\.jpe?g$/i', $f, $m)) {
        $localIds[(int) $m[1]] = $f;
    }
}

$ok = $fail = $skipped = 0;

foreach ($rows as $r) {
    if ($ok >= $limit) {
        break;
    }
    if (isset($localIds[(int) $r->id])) {
        $skipped++;
        continue;
    }

    echo str_repeat('=', 70).PHP_EOL;
    echo sprintf('#%d %s  £%.2f', $r->id, mb_strimwidth((string) $r->name, 0, 40), $r->price).PHP_EOL;
    echo 'src: '.$r->source_url.PHP_EOL;

    $ch = curl_init($byparr);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $r->source_url, 'max_timeout' => 45000]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 90,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (! is_string($resp) || $resp === '') {
        echo "  FETCH FAILED (no body, http {$code})".PHP_EOL;
        $fail++;
        continue;
    }
    // Byparr's response shape: the page is NOT in `message` (which is a 7-char
    // status string). It is in solution.response, with the HTTP status in
    // solution.status. Reading `message` is why this probe first reported a
    // 7-byte "page" and no image - the fetch was working all along.
    $j = json_decode($resp, true);
    $html = (string) ($j['solution']['response'] ?? '');
    $upstreamStatus = $j['solution']['status'] ?? null;

    printf("  byparr status=%s  upstream HTTP %s  html %d bytes".PHP_EOL,
        $j['status'] ?? '?', $upstreamStatus ?? '?', strlen($html));

    if (trim($html) === '') {
        echo "  NO PAGE BODY".PHP_EOL;
        $fail++;
        continue;
    }

    if (stripos($html, 'captcha') !== false || stripos($html, 'are you a robot') !== false
        || stripos($html, 'unusual traffic') !== false) {
        echo "  BLOCKED by anti-bot".PHP_EOL;
        $fail++;
        continue;
    }

    // The product shot: og:image is the most stable handle on a PCPP page.
    $img = null;
    if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
        $img = html_entity_decode($m[1]);
    } elseif (preg_match('#<img[^>]+id=["\']img["\'][^>]+src=["\']([^"\']+)#i', $html, $m)) {
        $img = $m[1];
    } elseif (preg_match_all('#https?://[^"\'\s>]+\.(?:jpg|jpeg|png|webp)#i', $html, $mm)) {
        foreach ($mm[0] as $cand) {
            if (preg_match('#(pcpartpicker|scsrv|media-amazon|cms-images)#i', $cand)) {
                $img = $cand;
                break;
            }
        }
    }

    if (! $img) {
        echo "  no image URL found in page".PHP_EOL;
        $fail++;
        continue;
    }

    echo '  image: '.mb_strimwidth($img, 0, 96).PHP_EOL;

    // PCPP's og:image advertises only a 256px thumbnail ("{hash}.256p.jpg"),
    // which is a grid thumbnail and too small for a product page. The same
    // page also references a much larger "{hash}.1600.jpg" (note: no "p"), so
    // the stem of the og:image hash is reused at the bigger sizes. Take the
    // largest that actually exists - do not assume a size.
    $candidates = [];
    if (preg_match('/\.(\d+)p\.jpg$/i', $img, $m)) {
        // $stem keeps the trailing dot that $m[0] consumed, so the dot must be
        // restored when appending a size. Dropping it produced
        // "...aa97be201600.jpg" instead of "...aa97be20.1600.jpg", which the CDN
        // answers with a 403 - and the code then silently fell back to the
        // thumbnail, which is why every image came out 256px.
        $stem = substr($img, 0, -strlen($m[0])).'.';
        foreach (['1600', '1000', '500', '250'] as $sz) {
            $candidates[] = $stem.$sz.'.jpg';
        }
        foreach (['1000p', '500p'] as $sz) {
            $candidates[] = $stem.$sz.'.jpg';
        }
    }
    $candidates[] = $img; // last resort: the thumbnail og:image gave us

    $bytes = null;
    $savedFrom = null;
    foreach (array_unique($candidates) as $cand) {
        $dh = curl_init(str_starts_with($cand, '//') ? 'https:'.$cand : $cand);
        curl_setopt_array($dh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $b = curl_exec($dh);
        $ct = (string) curl_getinfo($dh, CURLINFO_CONTENT_TYPE);
        $dc = (int) curl_getinfo($dh, CURLINFO_HTTP_CODE);
        $derr = curl_error($dh);
        curl_close($dh);

        if (getenv('GENIE_IMAGE_DEBUG')) {
            printf("    try %-22s http=%d type=%-12s size=%-8s err=%s".PHP_EOL,
                basename($cand), $dc, $ct, is_string($b) ? strlen($b) : 'FALSE', $derr ?: '-');
        }

        if (is_string($b) && strlen($b) > 3000 && str_contains($ct, 'image')) {
            $bytes = $b;
            $savedFrom = $cand;
            break;
        }
    }

    if ($bytes === null) {
        echo "  DOWNLOAD FAILED for all ".count(array_unique($candidates)).' candidate sizes'.PHP_EOL;
        $fail++;
        continue;
    }

    $path = $outDir.'/'.$r->id.'.jpg';
    file_put_contents($path, $bytes);
    printf("  SAVED %s.jpg  %.1f KB  from %s".PHP_EOL, $r->id, strlen($bytes) / 1024,
        basename(parse_url($savedFrom, PHP_URL_PATH) ?? $savedFrom));
    $ok++;
    sleep(3);
}

echo str_repeat('=', 70).PHP_EOL;
printf("probe result: ok=%d  failed=%d  skipped(already local)=%d".PHP_EOL, $ok, $fail, $skipped);
