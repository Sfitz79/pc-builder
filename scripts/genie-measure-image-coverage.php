<?php
// READ-ONLY MEASUREMENT. Answers one question before any write: if we point
// components.image_url at the local cache, how many rows would actually get a
// real image, and would any of them be pointed at a 404?
//
// This deliberately measures the CONSUMER's view, not the cache's view:
//   - the storefront renders <img :src="item.image_url"> (needs a usable path)
//   - BuilderController::partImage proxies textures and REQUIRES an http URL
//   - Component::displayImage() falls back to a category placeholder
// so "a file exists on disk" is not the same as "the customer sees a photo".
$root = __DIR__ . '/..';
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env.production.neon';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k).'='.trim(trim($v), '"'));
    $_ENV[trim($k)] = trim(trim($v), '"');
}
putenv('APP_ENV=production');

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// PRODUCTION GUARD: assert the driver before reading, or this reports local
// sqlite numbers as if they were production.
if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: expected the pgsql (Neon) driver, got '".DB::connection()->getDriverName()."'.\n");
    exit(2);
}

$dir = $root.'/public/img/components';

echo "=== 1. the cache on disk ===\n";
$all = [];
$byExt = [];
foreach (scandir($dir) ?: [] as $f) {
    if (preg_match('/^(\d+)\.(jpe?g|png|webp|gif)$/i', $f, $m)) {
        $all[(int) $m[1]] = $f;
        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $byExt[$e] = ($byExt[$e] ?? 0) + 1;
    }
}
printf("  %d cached file(s)\n", count($all));
foreach ($byExt as $e => $n) {
    printf("    .%-5s %d\n", $e, $n);
}

// The existing command only matches .jpe?g. Anything else is silently skipped,
// which would look like "no image available" when a photo is right there.
$nonJpg = array_filter($all, fn ($f) => ! preg_match('/\.jpe?g$/i', $f));
printf("  files the current command IGNORES (not .jpg): %d%s\n", count($nonJpg),
    $nonJpg ? '  e.g. '.implode(', ', array_slice(array_keys($nonJpg), 0, 8)) : '');

echo "\n=== 2. production image_url today ===\n";
$total = DB::table('components')->count();
$active = DB::table('components')->where('active', true)->count();
$set = DB::table('components')->whereNotNull('image_url')->where('image_url', '<>', '')->count();
printf("  components total            : %d\n", $total);
printf("  components active           : %d\n", $active);
printf("  image_url non-empty         : %d\n", $set);
printf("  image_url http(s)           : %d\n", DB::table('components')->where('image_url', 'like', 'http%')->count());
printf("  image_url root-relative     : %d\n", DB::table('components')->where('image_url', 'like', '/%')->count());

echo "\n=== 3. join: which component ids have a real cached file? ===\n";
$ids = DB::table('components')->pluck('id')->map(fn ($i) => (int) $i)->all();
$hit = array_values(array_intersect(array_keys($all), $ids));
$orphanFiles = array_values(array_diff(array_keys($all), $ids));
printf("  cached file MATCHES a component : %d\n", count($hit));
printf("  cached file with NO component    : %d (dead weight in the deploy)\n", count($orphanFiles));
printf("  active components WITH a file    : %d\n",
    DB::table('components')->where('active', true)->whereIn('id', $hit)->count());
printf("  active components WITHOUT a file : %d (these must keep the placeholder)\n",
    DB::table('components')->where('active', true)->whereNotIn('id', $hit)->count());

echo "\n=== 4. by category: what the customer would actually see ===\n";
$rows = DB::table('components')
    ->leftJoin('categories', 'components.category_id', '=', 'categories.id')
    ->where('components.active', true)
    ->select('categories.slug as slug')
    ->selectRaw('count(*) as n')
    ->selectRaw('sum(case when components.id in ('.implode(',', $hit ?: [0]).') then 1 else 0 end) as covered')
    ->groupBy('categories.slug')
    ->orderBy('n', 'desc')
    ->get();
printf("  %-14s %6s %9s %8s\n", 'category', 'active', 'has image', 'cover %');
$tc = $ta = 0;
foreach ($rows as $r) {
    $n = (int) $r->n;
    $c = (int) $r->covered;
    $tc += $c;
    $ta += $n;
    printf("  %-14s %6d %9d %7.1f%%\n", $r->slug ?? '(none)', $n, $c, $n ? $c / $n * 100 : 0);
}
printf("  %-14s %6d %9d %7.1f%%\n", 'TOTAL', $ta, $tc, $ta ? $tc / $ta * 100 : 0);

echo "\n=== 5. the two failure modes this must avoid ===\n";
printf("  if image_url were built from config('app.url'):\n");
printf("    that config is: %s\n", var_export(config('app.url'), true));
printf("    -> every stamped row would point at %s/img/components/...\n", rtrim((string) config('app.url'), '/'));
printf("  components whose file is 0 bytes (would render broken): %d\n",
    count(array_filter($all, fn ($f) => @filesize($dir.'/'.$f) < 1024)));
printf("  components with a file under 4KB (placeholder-quality): %d\n",
    count(array_filter($all, fn ($f) => @filesize($dir.'/'.$f) < 4096)));
