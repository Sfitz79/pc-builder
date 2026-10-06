<?php
/**
 * Measure the component-image gap against the LOCAL cache directory.
 *
 * WHY THIS EXISTS (2026-09-30): the "1,700 components with no image" figure came
 * from counting production image_url values. But the 2026-09-28 PCPP import had
 * already discovered a DIRECT CDN image URL for 2,634 components, and both
 * cdna.pcpartpicker.com and m.media-amazon.com serve those images over plain
 * HTTP right now (verified 200 / image/jpeg). Fetching a known image URL is a
 * completely different lane from rendering a PCPP product page, so the whole
 * gap may be closable without Byparr, ScraperAPI or any credit spend.
 *
 * READ-ONLY. Writes nothing. Always prints the driver first, because a bare
 * artisan command here would silently report on SQLite while production is
 * Postgres.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$driver = config('database.default');
echo "DRIVER      : {$driver} / " . config('database.connections.' . $driver . '.driver') . PHP_EOL;
echo "CACHE DIR   : public/img/components" . PHP_EOL . PHP_EOL;

$imgDir = public_path('img/components');
if (!is_dir($imgDir)) {
    echo "FATAL: cache dir does not exist: {$imgDir}" . PHP_EOL;
    exit(1);
}

$rows = DB::table('components')
    ->where('active', true)
    ->whereNotNull('image_url')
    ->where('image_url', 'like', 'http%')
    ->get(['id', 'category_id', 'name', 'image_url']);

$have = 0; $need = 0; $needByCat = []; $needIds = [];
foreach ($rows as $r) {
    $f = $imgDir . '/' . $r->id . '.jpg';
    if (is_file($f) && filesize($f) > 1024) { $have++; continue; }
    $need++;
    $needByCat[$r->category_id] = ($needByCat[$r->category_id] ?? 0) + 1;
    if (count($needIds) < 12) $needIds[] = [$r->id, $r->category_id, $r->name];
}

$totalCached = count(glob($imgDir . '/*.jpg') ?: []);

echo "components with a CDN image_url : " . count($rows) . PHP_EOL;
echo "  already cached locally (>1KB) : {$have}" . PHP_EOL;
echo "  NEED downloading              : {$need}" . PHP_EOL;
echo "files physically in cache dir   : {$totalCached}" . PHP_EOL . PHP_EOL;

ksort($needByCat);
echo "NEED-DOWNLOAD BREAKDOWN BY category_id" . PHP_EOL;
foreach ($needByCat as $cid => $n) echo "  cat {$cid} : {$n}" . PHP_EOL;
echo PHP_EOL;

echo "SAMPLE OF WHAT WOULD BE FETCHED (id | cat | name | host)" . PHP_EOL;
foreach ($needIds as [$id, $cat, $name]) {
    echo "  {$id} | {$cat} | " . substr((string) $name, 0, 44) . PHP_EOL;
}

// Distribution of source hosts, so we know which CDNs we depend on.
echo PHP_EOL . "SOURCE HOST DISTRIBUTION (of all rows needing a download)" . PHP_EOL;
$hosts = [];
foreach ($rows as $r) {
    $f = $imgDir . '/' . $r->id . '.jpg';
    if (is_file($f) && filesize($f) > 1024) continue;
    $h = parse_url($r->image_url, PHP_URL_HOST) ?: '(none)';
    $hosts[$h] = ($hosts[$h] ?? 0) + 1;
}
arsort($hosts);
foreach ($hosts as $h => $n) echo "  " . str_pad($h, 34) . " {$n}" . PHP_EOL;
