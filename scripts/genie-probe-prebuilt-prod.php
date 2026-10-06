<?php
// Genie 2026-09-29: the prebuilt assembler reads .env.production.neon, but my
// local probes were hitting database/database.sqlite. If the two disagree on
// CATEGORY NAMES, every local conclusion about this lane is wrong.
//
// Found by contradiction, not assumption: the assembler exits(1) if a category
// named 'Cooler' is missing, yet database/scraped/prebuilts.json contains a
// cooler part with an id that does not exist locally at all (39188). Local
// sqlite calls the category 'CPU Cooler'. One of those two facts can only be
// true of production, so local is not representative.
//
// This reads PRODUCTION and says so on every line.
require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
foreach (file($root . '/.env.production.neon') as $line) {
    if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
        $v = trim($m[2]);
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        putenv($m[1] . '=' . $v);
        $_ENV[$m[1]] = $v;
    }
}
putenv('DB_CONNECTION=pgsql');

$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once $root . '/scripts/genie-prod-guard.php'; // exits 3 unless pgsql

echo "PRODUCTION driver: " . DB::connection()->getDriverName() . "\n\n";

echo "=== CATEGORIES ===\n";
foreach (DB::table('categories')->get() as $c) {
    $n = DB::table('components')->where('category_id', $c->id)->where('active', 1)->count();
    printf("  id=%-4d %-24s active=%d\n", $c->id, $c->name, $n);
}

echo "\n=== The cooler from prebuilts.json (id 39188) ===\n";
$r = DB::table('components')->where('id', 39188)->first();
if ($r) {
    $cat = DB::table('categories')->where('id', $r->category_id)->value('name');
    printf("  FOUND %s  cat=%s  price=%.2f\n", $r->name, $cat, $r->price);
} else {
    echo "  id 39188 does not exist in production either\n";
}

echo "\n=== Spec coverage in production (the Rule 1 question) ===\n";
foreach (DB::table('categories')->get() as $c) {
    $rows = DB::table('components')->where('category_id', $c->id)->where('active', 1)->whereNotNull('price')->get();
    $with = 0;
    $keys = [];
    foreach ($rows as $r) {
        $s = $r->specs;
        if (is_string($s)) {
            $s = json_decode($s, true) ?: [];
        }
        if (is_array($s) && $s) {
            $with++;
            foreach (array_keys($s) as $k) {
                $keys[$k] = ($keys[$k] ?? 0) + 1;
            }
        }
    }
    arsort($keys);
    $out = [];
    foreach (array_slice($keys, 0, 5, true) as $k => $n) {
        $out[] = "$k=$n";
    }
    printf("  %-14s priced=%-4d withSpecs=%-4d  %s\n", $c->name, $rows->count(), $with, implode(' ', $out));
}
