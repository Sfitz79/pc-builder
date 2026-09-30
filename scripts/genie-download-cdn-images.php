<?php
/**
 * Close the component-image gap using CDN URLs we ALREADY KNOW.
 *
 * WHY (2026-09-30): the catalogue is duplicated across two databases - local
 * SQLite (ids 1-2729) and production Neon (ids 38930-41637) - so component ids
 * can never join between them. But both hold the same catalogue, and local
 * SQLite already carries a direct CDN image url for 1,849 distinct component
 * names. Measured against production: 1,995 of the 2,022 rows with no image
 * match a known name, so the gap is closable with plain HTTP GETs against
 * cdna.pcpartpicker.com / m.media-amazon.com. No PCPP page rendering, no
 * Byparr, no ScraperAPI, no credit spend.
 *
 * SAFETY
 * - Downloads only. Never deletes, never writes the database.
 * - Refuses to write a file unless it is really a JPEG over 1KB, so an error
 *   page can never be cached as a product photo.
 * - Resumable: an existing valid file is skipped.
 * - --limit keeps a validation batch small before any full run.
 * - --dry-run reports the plan and touches nothing.
 *
 * Usage:
 *   php scripts/genie-download-cdn-images.php --dry-run
 *   php scripts/genie-download-cdn-images.php --limit=25
 *   php scripts/genie-download-cdn-images.php --delay=350
 */
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? '1';
}
$dryRun  = isset($opts['dry-run']);
$limit   = (int)($opts['limit'] ?? 0);
$delay   = (int)($opts['delay'] ?? 250);
// One threshold, used by BOTH the fetcher and every "do I already have it"
// check. These were 4096 and 1024 respectively, so files between the two sizes
// were accepted on write but never counted as present, and got re-downloaded on
// every single run while the file count stayed flat.
$minSize = 1024;

echo "MODE          : " . ($dryRun ? 'DRY RUN (nothing written)' : 'DOWNLOAD') . PHP_EOL;
echo "delay         : {$delay}ms   min bytes: {$minSize}" . PHP_EOL . PHP_EOL;

// ---- production credentials ----------------------------------------------
$prodEnv = $root . '/.env.production.neon';
if (!is_file($prodEnv)) { echo "FATAL: no {$prodEnv}" . PHP_EOL; exit(1); }
$e = [];
foreach (file($prodEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) $e[$m[1]] = trim(trim($m[2]), "\"'");
}

// ---- local sqlite: name -> cdn url ---------------------------------------
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "LOCAL driver  : " . config('database.default') . PHP_EOL;

$norm = fn(?string $s) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
$byName = [];
foreach (DB::table('components')->whereNotNull('image_url')->where('image_url', 'like', 'http%')->get(['name', 'image_url']) as $r) {
    $k = $norm($r->name);
    if ($k !== '' && !isset($byName[$k])) $byName[$k] = $r->image_url;
}
echo "LOCAL name map: " . count($byName) . PHP_EOL;

// ---- production: rows still missing an image ------------------------------
Config::set('database.connections.prod_probe', [
    'driver' => 'pgsql', 'host' => $e['DB_HOST'], 'port' => $e['DB_PORT'] ?? '5432',
    'database' => $e['DB_DATABASE'], 'username' => $e['DB_USERNAME'], 'password' => $e['DB_PASSWORD'],
    'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => $e['DB_SSLMODE'] ?? 'require',
]);
DB::purge('prod_probe');

// Assert the driver rather than assume it. This script reads BOTH databases on
// purpose (local SQLite for the name->cdn url map, Neon for the rows that need
// the image), so "which database am I on" is genuinely ambiguous here and must
// be proven, not inferred. Exits non-zero if the production side is not pgsql.
$prodDriver = DB::connection('prod_probe')->getDriverName();
if ($prodDriver !== 'pgsql') {
    echo "FATAL: prod_probe driver is '{$prodDriver}', expected 'pgsql'. Refusing to read the wrong catalogue." . PHP_EOL;
    exit(4);
}
echo "PROD driver  : {$prodDriver} (asserted)" . PHP_EOL;

$prodMissing = DB::connection('prod_probe')->table('components')
    ->whereNull('image_url')->orWhere('image_url', '')
    ->get(['id', 'name']);
echo "PROD missing  : " . $prodMissing->count() . PHP_EOL . PHP_EOL;

$imgDir = $root . '/public/img/components';
@mkdir($imgDir, 0775, true);

$todo = []; $unmatched = [];
foreach ($prodMissing as $r) {
    $k = $norm($r->name);
    $url = $k !== '' ? ($byName[$k] ?? null) : null;
    if (!$url) { $unmatched[] = $r; continue; }
    $todo[] = ['id' => (int)$r->id, 'name' => (string)$r->name, 'url' => $url];
}

echo "PLAN" . PHP_EOL;
echo "  matched by name (will fetch) : " . count($todo) . PHP_EOL;
echo "  unmatched (need PCPP page)   : " . count($unmatched) . PHP_EOL;

$needBytes = 0; $alreadyThere = 0;
foreach ($todo as $t) {
    $f = $imgDir . '/' . $t['id'] . '.jpg';
    if (is_file($f) && filesize($f) >= $minSize) { $alreadyThere++; continue; }
    $needBytes += 30000;
}
echo "  already present, will skip  : {$alreadyThere}" . PHP_EOL;
echo "  will download               : " . (count($todo) - $alreadyThere) . "  (~" . round($needBytes / 1048576) . " MB est.)" . PHP_EOL;

$thumbs = array_filter($todo, fn($t) => str_contains($t['url'], '.256p.'));
echo "  of which .256p thumbnails   : " . count($thumbs) . " (will try the full-size variant first)" . PHP_EOL . PHP_EOL;

if ($unmatched) {
    echo "UNMATCHED SAMPLE (genuinely need a PCPP page fetch)" . PHP_EOL;
    foreach (array_slice($unmatched, 0, 8) as $u) echo "  [{$u->id}] " . substr((string)$u->name, 0, 60) . PHP_EOL;
    echo PHP_EOL;
}

if ($dryRun) { echo "DRY RUN COMPLETE - nothing written." . PHP_EOL; exit(0); }
if ($limit > 0) {
    // Sample rows that will ACTUALLY be fetched. Previously --limit sliced the
    // front of $todo, but that front is dominated by already-cached rows, so a
    // 25-row validation run reported "ok=0 skipped=25" - green, and proof of
    // nothing. A validation batch that fetches nothing is worse than no batch.
    $need = array_values(array_filter($todo, function ($t) use ($imgDir, $minSize) {
        $f = $imgDir . '/' . $t['id'] . '.jpg';
        return !(is_file($f) && filesize($f) >= $minSize);
    }));
    $todo = array_slice($need, 0, $limit);
    echo "LIMITED TO {$limit} FOR VALIDATION (drawn from " . count($need) . " rows that actually need fetching)" . PHP_EOL . PHP_EOL;
}

$UA = 'pctechguyonline-builder/1.0 (+https://pctechguyonline.com; info@pctechguyonline.com)';
echo "floor        : {$minSize} bytes (JPEG magic bytes are the real gate)" . PHP_EOL;
$ok = 0; $fail = 0; $skipped = 0; $bytes = 0;
$notes = [];
$report = [];

/**
 * Fetch one image and only accept it if it is genuinely a JPEG of sane size.
 * Writes to a .part file first so an interrupted run never leaves a truncated
 * image that a later run would treat as complete.
 */
function fetchImage(string $url, string $dest, string $UA, int $floor): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        // CURLOPT_URL must be set here. It was missing, so every attempt
        // returned curl errno 3 ("URL malformat") because the $url parameter
        // was accepted and then silently discarded - a green-looking loop
        // that downloaded nothing.
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => $UA,
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);

    // A silent "http 0" is not a diagnosis. Name the transport-level failure.
    if ($errno !== 0) {
        return ['ok' => false, 'why' => 'curl errno ' . $errno . ': ' . ($err ?: 'no detail')];
    }
    if ($code !== 200) return ['ok' => false, 'why' => "http {$code}"];
    $len = strlen((string)$body);

    // The declared content-type is NOT the test. cdna.pcpartpicker.com and
    // m.media-amazon.com both label genuine product JPEGs as
    // binary/octet-stream, and rejecting on the header alone silently threw
    // away real photos (Noctua NH-D15S, be quiet! Pure Base 600). The honest
    // question is whether the bytes are a JPEG, so test the magic bytes and
    // REPORT the content-type mismatch rather than fail on it.
    $isJpeg = substr((string)$body, 0, 3) === "\xFF\xD8\xFF";
    if (!$isJpeg) {
        return ['ok' => false, 'why' => 'not a JPEG (magic bytes)' . ($ctype ? ", served as {$ctype}" : '')];
    }
    // Floor is low enough to keep real small product photos, high enough that
    // no error page survives. The magic-byte test above is what actually
    // rejects an HTML error page served with a 200.
    if ($len < $floor) return ['ok' => false, 'why' => "too small ({$len}b)"];

    $tmp = $dest . '.part';
    if (file_put_contents($tmp, $body) === false) return ['ok' => false, 'why' => 'write failed'];
    rename($tmp, $dest);
    return [
        'ok' => true,
        'bytes' => $len,
        'note' => (!str_contains($ctype, 'image/')) ? "accepted a JPEG served as '{$ctype}'" : '',
    ];
}

$t0 = microtime(true);
foreach ($todo as $i => $t) {
    $dest = $imgDir . '/' . $t['id'] . '.jpg';
    if (is_file($dest) && filesize($dest) >= $minSize) { $skipped++; continue; }

    // Prefer the full-size variant over an explicit 256px thumbnail.
    $candidates = [$t['url']];
    if (str_contains($t['url'], '.256p.')) {
        array_unshift($candidates, str_replace('.256p.', '.', $t['url']));
    }

    $done = false; $why = 'no attempt';
    foreach ($candidates as $cand) {
        $r = fetchImage($cand, $dest, $UA, $minSize);
        if ($r['ok']) {
            $ok++; $bytes += $r['bytes']; $done = true;
            if (!empty($r['note'])) $notes[] = "[{$t['id']}] " . $r['note'];
            break;
        }
        $why = $r['why'];
    }
    if (!$done) {
        $fail++;
        $report[] = ['id' => $t['id'], 'name' => $t['name'], 'url' => $t['url'], 'why' => $why];
    }

    if (($i + 1) % 25 === 0) {
        $el = microtime(true) - $t0;
        printf("  %d/%d  ok=%d fail=%d skip=%d  %.0f MB  %.0fs\n", $i + 1, count($todo), $ok, $fail, $skipped, $bytes / 1048576, $el);
    }
    if ($delay > 0) usleep($delay * 1000);
}

printf(PHP_EOL . "DONE  ok=%d  fail=%d  skipped=%d  downloaded=%.1f MB  in %.0fs" . PHP_EOL, $ok, $fail, $skipped, $bytes / 1048576, microtime(true) - $t0);

if ($report) {
    $rp = $root . '/database/snapshots/cdn-image-failures-' . date('Ymd-His') . '.json';
    @mkdir(dirname($rp), 0775, true);
    file_put_contents($rp, json_encode($report, JSON_PRETTY_PRINT));
    echo "failure report: {$rp}" . PHP_EOL;
    foreach (array_slice($report, 0, 10) as $r) echo "  [{$r['id']}] {$r['why']}  " . substr($r['name'], 0, 40) . PHP_EOL;
}
