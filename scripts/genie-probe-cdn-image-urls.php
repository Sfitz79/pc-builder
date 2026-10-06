<?php
// Probe the LOCAL SQLite cache of component image URLs.
// Purpose: the 2026-09-28 PCPP import already discovered the direct CDN image
// URL for many components. If those URLs are still live, the "0% coverage" gap
// for GPU/RAM/storage/case/PSU can be closed by downloading known image URLs,
// with NO PCPP page rendering at all.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$http   = DB::table('components')->where('image_url', 'like', 'http%')->count();
$root   = DB::table('components')->where('image_url', 'like', '/img/%')->count();
$total  = DB::table('components')->count();
$nulls  = DB::table('components')->whereNull('image_url')->count();

echo "DRIVER           : " . config('database.default') . " / " . config('database.connections.' . config('database.default') . '.driver') . PHP_EOL;
echo "total components : {$total}" . PHP_EOL;
echo "image_url NULL   : {$nulls}" . PHP_EOL;
echo "image_url http(s): {$http}   <- CDN URLs discovered by the earlier PCPP import" . PHP_EOL;
echo "image_url /img/  : {$root}" . PHP_EOL . PHP_EOL;

echo "SAMPLE CDN URLS" . PHP_EOL;
$rows = DB::table('components')
    ->where('image_url', 'like', 'http%')
    ->inRandomOrder()
    ->limit(5)
    ->get(['id', 'category_id', 'name', 'image_url']);
foreach ($rows as $r) {
    echo "  [{$r->id}] {$r->category_id} " . substr((string) $r->name, 0, 42) . PHP_EOL;
    echo "        " . $r->image_url . PHP_EOL;
}

// Per-category breakdown of what is still missing a local cached file.
echo PHP_EOL . "MISSING-IMAGE BREAKDOWN (image_url IS NULL, by category)" . PHP_EOL;
$byCat = DB::table('components')
    ->whereNull('image_url')
    ->select('category_id', DB::raw('COUNT(*) as n'))
    ->groupBy('category_id')
    ->orderByDesc('n')
    ->get();
foreach ($byCat as $c) {
    echo "  " . str_pad((string) $c->category, 14) . " {$c->n}" . PHP_EOL;
}

// How many missing ones still carry a PCPP source_url we could fetch against.
$missPcpp = DB::table('components')
    ->whereNull('image_url')
    ->where('source_url', 'like', '%pcpartpicker%')
    ->count();
echo PHP_EOL . "missing image BUT has pcpartpicker source_url : {$missPcpp}" . PHP_EOL;


