<?php
// Before writing any fetcher, establish WHAT we have to fetch with.
// Rule 4: verify shapes from live data, never from memory.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

$caseId = DB::table('categories')->where('name', 'Case')->value('id');

$rows = DB::table('components')
    ->where('category_id', $caseId)
    ->where('active', 1)
    ->get(['id', 'name', 'slug', 'sku', 'source_url', 'image_url', 'description', 'specs']);

printf("active cases          : %d\n", $rows->count());
printf("with source_url       : %d\n", $rows->filter(fn ($r) => $r->source_url !== null && $r->source_url !== '')->count());
printf("with sku              : %d\n", $rows->filter(fn ($r) => $r->sku !== null && $r->sku !== '')->count());
printf("with slug             : %d\n", $rows->filter(fn ($r) => $r->slug !== null && $r->slug !== '')->count());
printf("with description      : %d\n", $rows->filter(fn ($r) => $r->description !== null && $r->description !== '')->count());

echo "\n-- host distribution of source_url --\n";
$hosts = [];
foreach ($rows as $r) {
    if (!$r->source_url) {
        continue;
    }
    $h = parse_url($r->source_url, PHP_URL_HOST) ?: '?';
    $hosts[$h] = ($hosts[$h] ?? 0) + 1;
}
arsort($hosts);
foreach (array_slice($hosts, 0, 12, true) as $h => $n) {
    printf("  %-38s %4d\n", $h, $n);
}

echo "\n-- 8 sample source_url values --\n";
foreach ($rows->filter(fn ($r) => $r->source_url)->take(8) as $r) {
    printf("  %-46s %s\n", substr((string) $r->name, 0, 46), substr((string) $r->source_url, 0, 96));
}

echo "\n-- does specs JSON carry anything case-related at all? --\n";
$keys = [];
foreach ($rows as $r) {
    $s = $r->specs;
    if (is_string($s)) {
        $s = json_decode($s, true);
    }
    if (is_array($s)) {
        foreach (array_keys($s) as $k) {
            $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
    }
}
arsort($keys);
foreach ($keys as $k => $n) {
    printf("  %-24s %4d\n", $k, $n);
}
if ($keys === []) {
    echo "  (specs is empty for every case row)\n";
}

echo "\n-- description shape: is it real prose or a spec blob? --\n";
foreach ($rows->take(3) as $r) {
    printf("  %s\n    %s\n", substr((string) $r->name, 0, 50), substr(preg_replace('/\s+/', ' ', (string) $r->description), 0, 150));
}
