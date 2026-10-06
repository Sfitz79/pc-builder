<?php
// Genie 2026-09-28: two questions, answered with data instead of assumption.
//
// Q1. Why quarantine PSU/Case/Cooler? I claimed "specs is empty so we don't
//     know what they are". But components has a `wattage` COLUMN, and the
//     compatibility logic may read columns rather than specs. If PSU wattage is
//     populated in the column, then "empty specs" is cosmetic and quarantining
//     would be pure self-harm. Measure it.
//
// Q2. Can images be carried on? The cache is keyed to an id space of 23-2729
//     while production runs 38906-40887, so a direct id join is impossible. But
//     if there is a local SQLite catalogue from the same scrape, then
//     (local id -> image) plus (local name+price -> production id) could still
//     recover most of the set. Test whether name+price is a usable join key.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Q1: is 'empty specs' actually a problem? ===".PHP_EOL;

foreach (['PSU', 'Case', 'Cooler'] as $catName) {
    $c = DB::table('categories')->where('name', $catName)->first();
    $rows = DB::table('components')->where('category_id', $c->id)->where('active', true)
        ->get(['id', 'name', 'wattage', 'specs', 'description']);
    $specsEmpty = 0;
    $wattageSet = 0;
    $descSet = 0;
    foreach ($rows as $r) {
        $s = json_decode((string) $r->specs, true);
        if (! is_array($s) || $s === []) {
            $specsEmpty++;
        }
        if ($r->wattage !== null && $r->wattage !== '') {
            $wattageSet++;
        }
        if ($r->description !== null && trim((string) $r->description) !== '') {
            $descSet++;
        }
    }
    printf("  %-8s active=%-4d specs empty=%-4d  wattage COLUMN set=%-4d  description set=%-4d".PHP_EOL,
        $catName, $rows->count(), $specsEmpty, $wattageSet, $descSet);
}

echo PHP_EOL."  sample PSU rows (what a customer would actually see):".PHP_EOL;
$p = DB::table('categories')->where('name', 'PSU')->first();
foreach (DB::table('components')->where('category_id', $p->id)->where('active', true)
    ->where('wattage', '>', 0)->orderBy('price')->limit(5)->get() as $r) {
    printf("    £%-7.2f #%-6d %-30s wattage_col=%-5s desc=%s".PHP_EOL, $r->price, $r->id,
        mb_strimwidth((string) $r->name, 0, 30), $r->wattage ?: '-',
        mb_strimwidth((string) $r->description, 0, 60) ?: '(none)');
}
$noWatt = DB::table('components')->where('category_id', $p->id)->where('active', true)
    ->where(function ($w) { $w->whereNull('wattage')->orWhere('wattage', 0); })->count();
printf("  PSUs with NO wattage in column: %d of %d".PHP_EOL, $noWatt,
    DB::table('components')->where('category_id', $p->id)->where('active', true)->count());

echo PHP_EOL."=== Q2: can the image cache be joined to production? ===".PHP_EOL;
$dbFile = database_path('database.sqlite');
if (! file_exists($dbFile)) {
    echo "  no local SQLite at {$dbFile} - no second id space to join from".PHP_EOL;
} else {
    try {
        $l = new PDO('sqlite:'.$dbFile);
        $row = $l->query('SELECT MIN(id) a, MAX(id) b, COUNT(*) c FROM components')->fetch(PDO::FETCH_ASSOC);
        printf("  local sqlite: id %s to %s, %s components".PHP_EOL, $row['a'], $row['b'], $row['c']);

        // name+price uniqueness on the local side, and match rate against prod.
        $local = $l->query('SELECT id, name, price, category_id FROM components')->fetchAll(PDO::FETCH_ASSOC);
        $keyed = [];
        foreach ($local as $r) {
            $k = mb_strtoupper(trim((string) $r['name'])).'|'.number_format((float) $r['price'], 2);
            $keyed[$k][] = (int) $r['id'];
        }
        $dupe = 0;
        foreach ($keyed as $v) {
            if (count($v) > 1) {
                $dupe++;
            }
        }
        printf("  local name+price keys: %d, of which ambiguous (>1 row): %d".PHP_EOL, count($keyed), $dupe);

        $hit = $amb = 0;
        foreach (DB::table('components')->where('active', true)->get(['name', 'price']) as $r) {
            $k = mb_strtoupper(trim((string) $r->name)).'|'.number_format((float) $r->price, 2);
            if (! isset($keyed[$k])) {
                continue;
            }
            if (count($keyed[$k]) === 1) {
                $hit++;
            } else {
                $amb++;
            }
        }
        printf("  production rows matching a UNIQUE local key: %d   (ambiguous: %d)".PHP_EOL, $hit, $amb);

        // How many production rows would end up with a resolvable image id?
        $images = [];
        foreach (scandir(public_path('img/components')) ?: [] as $f) {
            if (preg_match('/^(\d+)\.jpe?g$/i', $f, $m)) {
                $images[(int) $m[1]] = $f;
            }
        }
        $matched = 0;
        foreach (DB::table('components')->where('active', true)->get(['name', 'price']) as $r) {
            $k = mb_strtoupper(trim((string) $r->name)).'|'.number_format((float) $r->price, 2);
            if (isset($keyed[$k]) && count($keyed[$k]) === 1 && isset($images[$keyed[$k][0]])) {
                $matched++;
            }
        }
        printf("  production rows that would resolve to a real image file: %d of %d".PHP_EOL,
            $matched, DB::table('components')->where('active', true)->count());
    } catch (Throwable $e) {
        echo '  ERR '.$e->getMessage().PHP_EOL;
    }
}
