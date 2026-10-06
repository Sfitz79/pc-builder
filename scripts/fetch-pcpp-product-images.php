<?php
// Genie 2026-09-28: bulk-recover product photography for the whole catalogue
// via Byparr -> PCPartPicker -> PCPP's own image CDN.
//
// The chain (proven on 2026-09-28):
//   components.source_url (every active row has a PCPP product page)
//     -> Byparr POST /v1 renders it (bypasses the anti-bot that blocks plain HTTP)
//     -> og:image in the returned HTML gives a cdna.pcpartpicker.com URL
//     -> the same page also references a {hash}.1600.jpg, which is the real
//        product photo rather than the 256p grid thumbnail
//     -> download it into public/img/components/<component-id>.jpg
//
// Vercel then serves it as the CDN. No third-party image account needed.
//
// DESIGN NOTES THAT MATTER:
//  - Resumable: a component whose file already exists is skipped, so the job can
//    be killed and restarted without re-fetching or losing work.
//  - Throttled (default 3s between page fetches). ~2,700 products at 3s is a
//    couple of hours. Being a good citizen of someone else's CDN is the point:
//    this reads their product pages thousands of times.
//  - Never guesses. If Byparr fails, the page is blocked, or no image can be
//    found, the component is logged as failed and left with NO image rather than
//    being pointed at a plausible-looking URL.
//  - Idempotent: re-running only fills gaps.
//
// Usage:  php scripts/fetch-pcpp-product-images.php [maxToProcess] [sleepSeconds]
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$byparr = 'http://127.0.0.1:8191/v1';
$outDir = public_path('img/components');
$limit = (int) ($argv[1] ?? 0);        // 0 = no limit
$nap = (int) ($argv[2] ?? 3);
$reportPath = database_path('scraped/pcpp-image-fetch-report.json');

// Health check first: Byparr answers /health even when every fetch 500s, so a
// green health check alone is not evidence it works.
$probe = @file_get_contents('http://127.0.0.1:8191/health');
if ($probe === false) {
    fwrite(STDERR, "FATAL: Byparr is not answering on 8191. Start it via scripts/launch-byparr.ps1".PHP_EOL);
    exit(1);
}
fwrite(STDERR, 'byparr: '.trim($probe).PHP_EOL);

$rows = DB::table('components')
    ->where('active', true)
    ->whereNotNull('source_url')
    ->where('source_url', 'LIKE', '%pcpartpicker%')
    ->orderBy('id')
    ->get(['id', 'name', 'source_url']);

fwrite(STDERR, sprintf('candidate components: %d  (nap %ds, limit %s)'.PHP_EOL,
    $rows->count(), $nap, $limit ?: 'none'));

$report = file_exists($reportPath)
    ? (json_decode(file_get_contents($reportPath), true) ?: ['ok' => [], 'failed' => []])
    : ['ok' => [], 'failed' => []];

$stats = ['ok' => 0, 'skipped' => 0, 'failed' => 0, 'nobyparr' => 0, 'noimage' => 0, 'nodl' => 0];
$started = time();

/**
 * Record a failure with enough context to tell a genuine data gap from a
 * broken fetch.
 *
 * Every failure used to be a bare reason string, which made a report full of
 * "no image url in page" undiagnosable: it could mean PCPP genuinely has no
 * photo for that product, or that Byparr handed back a truncated page and the
 * extractor found nothing in it. Those look identical in the old report.
 *
 * html_bytes is the discriminator. A real PCPP product page renders to roughly
 * 180 KB; a 2 KB "page" is a broken fetch, not a product without a photo.
 */
$recordFailure = function (int $id, string $reason, string $name, string $url, array $extra = []) use ($reportPath, &$report): void {
    $report['failed'][$id] = array_merge([
        'reason' => $reason,
        'name'   => $name,
        'url'    => $url,
    ], $extra);

    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};


foreach ($rows as $r) {
    if ($limit && ($stats['ok'] + $stats['skipped'] + $stats['failed']) >= $limit) {
        fwrite(STDERR, 'limit reached'.PHP_EOL);
        break;
    }

    $file = $outDir.'/'.$r->id.'.jpg';
    if (is_file($file) && filesize($file) > 3000) {
        $stats['skipped']++;
        continue;
    }

    // 1. render the PCPP page through Byparr
    $ch = curl_init($byparr);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $r->source_url, 'max_timeout' => 45000]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 90,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $j = is_string($resp) ? json_decode($resp, true) : null;
    $html = (string) ($j['solution']['response'] ?? '');

    if (trim($html) === '') {
        $stats['failed']++;
        $stats['nobyparr']++;
        $recordFailure((int) $r->id, 'no page body from byparr', (string) $r->name, (string) $r->source_url, [
            'byparr_said' => substr((string) ($j['message'] ?? ''), 0, 200),
        ]);
        continue;
    }

    if (preg_match('/captcha|are you a robot|unusual traffic|access denied/i', $html)) {
        $stats['failed']++;
        $stats['nobyparr']++;
        $recordFailure((int) $r->id, 'anti-bot block', (string) $r->name, (string) $r->source_url, [
            'html_bytes' => strlen($html),
        ]);
        fwrite(STDERR, sprintf('BLOCKED on #%d - pausing 60s'.PHP_EOL, $r->id));
        sleep(60);
        continue;
    }

    // 1b. PCPP serves a ~1.2 KB page titled "Unavailable" with no og:image once
    //     it decides this client is unwelcome. It arrives with HTTP 200 and a
    //     perfectly normal-looking body, so without this check it is silently
    //     recorded as "no image url in page" - which reads as a missing product
    //     photo and quietly poisons the catalogue with a fake data gap. Detect
    //     it, name it, and stop hammering the site while it lasts.
    $pageTitle = preg_match('#<title[^>]*>(.+?)</title>#is', $html, $tm)
        ? trim(html_entity_decode(strip_tags($tm[1])))
        : null;

    $looksBlocked = (is_string($pageTitle) && stripos($pageTitle, 'unavailable') !== false)
        || (strlen($html) < 5000
            && ! preg_match('#og:image#i', $html)
            && ! preg_match('#<img\b#i', $html));

    if ($looksBlocked) {
        $stats['failed']++;
        $stats['blocked'] = ($stats['blocked'] ?? 0) + 1;
        $recordFailure((int) $r->id, 'pcpp blocked this client (Unavailable page)', (string) $r->name, (string) $r->source_url, [
            'html_bytes' => strlen($html),
            'page_title' => $pageTitle,
        ]);

        // Give up rather than burn hours producing identical false failures.
        fwrite(STDERR, sprintf(
            'BLOCKED by PCPP on #%d (%d byte page, title=%s) - stopping, not a data gap'.PHP_EOL,
            $r->id, strlen($html), var_export($pageTitle, true)
        ));
        break;
    }

    // 2. the product image. og:image is the authoritative pointer to THIS
    //    product; other hashes on the page are "also consider" suggestions, so
    //    the stem is always taken from og:image and never from a page-wide scan.
    $img = null;
    if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
        $img = html_entity_decode($m[1]);
    } elseif (preg_match('#<img[^>]+id=["\']img["\'][^>]+src=["\']([^"\']+)#i', $html, $m)) {
        $img = $m[1];
    }

    if (! $img) {
        $stats['failed']++;
        $stats['noimage']++;

        // Distinguish "PCPP has no photo for this product" from "we were handed
        // a page that never rendered". Recording the raw signals - size, title,
        // whether any img markup exists - keeps this failure diagnosable. A
        // "rendered" size threshold is deliberately NOT recorded here: the
        // obvious reference page renders to just 1,208 bytes when PCPP is
        // blocking, and inventing a cut-off from an unmeasured guess would be
        // worse than no threshold at all.
        $recordFailure((int) $r->id, 'no image url in page', (string) $r->name, (string) $r->source_url, [
            'html_bytes'  => strlen($html),
            'has_title'   => (bool) preg_match('#<title[^>]*>(.+?)</title>#is', $html, $tm),
            'page_title'  => isset($tm[1]) ? trim(html_entity_decode(strip_tags($tm[1]))) : null,
            'any_img_tag' => (bool) preg_match('#<img\b#i', $html),
            'og_any'      => (bool) preg_match('#og:image#i', $html),
        ]);

        continue;
    }

    // 3. prefer the full-size photo over the 256p thumbnail
    $candidates = [];
    if (preg_match('/\.(\d+)p\.jpg$/i', $img, $m)) {
        $stem = substr($img, 0, -strlen($m[0])).'.';
        foreach (['1600', '1000', '500', '250'] as $sz) {
            $candidates[] = $stem.$sz.'.jpg';
        }
    }
    $candidates[] = $img;

    $bytes = null;
    $from = null;
    $tried = [];
    foreach (array_unique($candidates) as $cand) {
        if (str_starts_with($cand, '//')) {
            $cand = 'https:'.$cand;
        }
        $dh = curl_init($cand);
        curl_setopt_array($dh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $b = curl_exec($dh);
        $ct = (string) curl_getinfo($dh, CURLINFO_CONTENT_TYPE);
        $code = (int) curl_getinfo($dh, CURLINFO_HTTP_CODE);
        curl_close($dh);

        // Record every attempt, including the rejected ones. A silently
        // skipped 403 on a preferred size is exactly how a run once "succeeded"
        // at the wrong quality for several iterations without anyone noticing.
        $tried[] = [
            'url'    => $cand,
            'http'   => $code,
            'type'   => $ct,
            'bytes'  => is_string($b) ? strlen($b) : 0,
            'used'   => false,
        ];

        if (is_string($b) && strlen($b) > 3000 && str_contains($ct, 'image')) {
            $bytes = $b;
            $from = $cand;
            $tried[count($tried) - 1]['used'] = true;
            break;
        }
    }

    if ($bytes === null) {
        $stats['failed']++;
        $stats['nodl']++;
        $recordFailure((int) $r->id, 'image url found but download failed', (string) $r->name, (string) $r->source_url, [
            'og_image' => $img,
            'tried'    => $tried,
        ]);
        continue;
    }

    file_put_contents($file, $bytes);
    $stats['ok']++;
    unset($report['failed'][$r->id]);
    $report['ok'][$r->id] = ['name' => $r->name, 'src' => $from, 'kb' => round(strlen($bytes) / 1024, 1)];
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if ($stats['ok'] % 10 === 0) {
        $el = time() - $started;
        fwrite(STDERR, sprintf(
            'ok=%d skipped=%d failed=%d  last=#%d  %.1f min elapsed'.PHP_EOL,
            $stats['ok'], $stats['skipped'], $stats['failed'], $r->id, $el / 60
        ));
    }

    sleep($nap);
}

fwrite(STDERR, PHP_EOL.'=== DONE ==='.PHP_EOL);
foreach ($stats as $k => $v) {
    fwrite(STDERR, sprintf('  %-10s %d'.PHP_EOL, $k, $v));
}
fwrite(STDERR, sprintf('  report: %s'.PHP_EOL, $reportPath));
