<?php
// Genie 2026-09-28: is the 199.9 MB local image cache actually needed in the
// repo? The storefront renders item.image_url, and ComponentImageService keeps
// the REMOTE vendor/CDN url in that column, using public/img/components only as
// a fallback cache ("so we keep a durable local asset if the CDN ever rots").
//
// If image_url is populated with absolute remote URLs, the local cache is
// redundant for rendering and can stay out of git and out of the deploy. If it
// is empty or holds relative paths, deleting the cache would 404 every product
// photo on the live site.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$active = DB::table('components')->where('active', true);
printf('active components          : %d'.PHP_EOL, $active->count());
printf('with non-empty image_url   : %d'.PHP_EOL, DB::table('components')->where('active', true)->whereNotNull('image_url')->where('image_url', '<>', '')->count());
printf('image_url is http(s)       : %d'.PHP_EOL, DB::table('components')->where('active', true)->where('image_url', 'like', 'http%')->count());
printf('image_url is RELATIVE      : %d'.PHP_EOL, DB::table('components')->where('active', true)->where('image_url', 'like', '/%')->count());

echo PHP_EOL.'=== host distribution of image_url ==='.PHP_EOL;
$hosts = [];
foreach (DB::table('components')->where('active', true)->whereNotNull('image_url')->where('image_url', '<>', '')->pluck('image_url') as $url) {
    $host = parse_url((string) $url, PHP_URL_HOST);
    $hosts[$host ?: 'RELATIVE/other'] = ($hosts[$host ?: 'RELATIVE/other'] ?? 0) + 1;
}
arsort($hosts);
foreach ($hosts as $host => $n) {
    printf('  %-46s %d'.PHP_EOL, $host, $n);
}

echo PHP_EOL.'=== sample rows ==='.PHP_EOL;
foreach (DB::table('components')->where('active', true)->whereNotNull('image_url')->where('image_url', '<>', '')->limit(3)->get() as $row) {
    printf('  [%d] %s'.PHP_EOL, $row->id, $row->image_url);
}
