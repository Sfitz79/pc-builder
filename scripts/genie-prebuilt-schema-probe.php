<?php

/**
 * Assemble COMPLETE, REAL, COMPATIBLE pre-built systems from PRODUCTION Postgres.
 *
 * A pre-built is a customer promise, so every part must exist in the live
 * catalogue, be correctly socketed, and have a real price. This script:
 *   1. reports the real spec keys available per category (no guessing),
 *   2. builds one system per GPU tier, matching CPU socket to motherboard socket,
 *   3. sizes the PSU with genuine headroom over measured component draw,
 *   4. writes config/prebuilts.php for the /prebuilts page.
 *
 * READ-ONLY against production. Writes only the local config file.
 */

$root = dirname(__DIR__);
foreach (file($root . '/.env.production.neon', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim(trim($v), "\"'");
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: not pgsql\n");
    exit(2);
}

$specs = static function ($r): array {
    return is_array($r->specs ?? null) ? $r->specs : (json_decode((string) ($r->specs ?? ''), true) ?: []);
};

echo "=== columns per category ===\n";
$cols = [];
foreach (DB::table('categories')->orderBy('id')->get() as $c) {
    $cols[$c->name] = Schema::getColumnListing('components');
    printf("  %-12s cols: %s\n", $c->name, implode(',', $cols[$c->name]));
}

echo "\n=== spec keys per category (top 12 by frequency) ===\n";
$allKeys = [];
foreach (DB::table('categories')->orderBy('id')->get() as $c) {
    $keys = [];
    $rows = DB::table('components')->where('category_id', $c->id)->where('active', 1)->take(150)->get();
    foreach ($rows as $r) {
        foreach (array_keys($specs($r)) as $k) {
            $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
    }
    arsort($keys);
    $allKeys[$c->name] = $keys;
    $top = array_slice($keys, 0, 12, true);
    $line = [];
    foreach ($top as $k => $n) {
        $line[] = "$k($n)";
    }
    printf("  %-12s %s\n", $c->name, implode(' ', $line));
}

// ---- socket detection, using whatever key actually holds it -----------------
$socketOf = static function (array $s): ?string {
    foreach (['socket', 'chipset', 'socket_name', 'cpu_socket'] as $k) {
        $v = $s[$k] ?? null;
        if (is_string($v) && preg_match('/\b(AM\d|LGA\s?\d{4}|LGA\s?\d{3}|TR4|sTRX)\b/i', $v, $m)) {
            return strtoupper(str_replace(' ', '', $m[0]));
        }
    }
    return null;
};

$cats = [];
foreach (DB::table('categories')->get() as $c) {
    $cats[$c->name] = $c->id;
}

echo "\n=== socket availability (compatibility is non-negotiable) ===\n";
$cpuSockets = [];
foreach (DB::table('components')->where('category_id', $cats['CPU'])->where('active', 1)->whereNotNull('price')->get() as $r) {
    $sk = $socketOf($specs($r));
    if ($sk) {
        $cpuSockets[$sk] = ($cpuSockets[$sk] ?? 0) + 1;
    }
}
$mbSockets = [];
foreach (DB::table('components')->where('category_id', $cats['Motherboard'])->where('active', 1)->whereNotNull('price')->get() as $r) {
    $sk = $socketOf($specs($r));
    if ($sk) {
        $mbSockets[$sk] = ($mbSockets[$sk] ?? 0) + 1;
    }
}
printf("  CPU sockets      : %s\n", json_encode($cpuSockets));
printf("  Motherboard socks: %s\n", json_encode($mbSockets));
printf("  MATCHABLE sockets: %s\n", json_encode(array_values(array_intersect(array_keys($cpuSockets), array_keys($mbSockets)))));

echo "\n=== PSU wattage column (dedicated column, not specs) ===\n";
$hasWattage = Schema::hasColumn('components', 'wattage');
printf("  components.wattage exists: %s\n", $hasWattage ? 'yes' : 'no');
if ($hasWattage) {
    $n = DB::table('components')->where('category_id', $cats['PSU'])->where('active', 1)->whereNotNull('wattage')->count();
    $t = DB::table('components')->where('category_id', $cats['PSU'])->where('active', 1)->count();
    printf("  populated: %d of %d active PSUs\n", $n, $t);
    foreach (DB::table('components')->where('category_id', $cats['PSU'])->where('active', 1)->whereNotNull('wattage')->orderBy('wattage')->get(['id', 'name', 'price', 'wattage']) as $p) {
        printf("    %-6s %-34s %5sW  GBP%s\n", $p->id, substr((string) $p->name, 0, 34), $p->wattage, number_format((float) $p->price, 0));
        break;
    }
}

echo "\n=== RAM capacity + Case form factor ===\n";
$ramKeys = array_keys($allKeys['RAM'] ?? []);
printf("  RAM keys: %s\n", implode(',', array_slice($ramKeys, 0, 10)));
$caseKeys = array_keys($allKeys['Case'] ?? []);
printf("  Case keys: %s\n", implode(',', array_slice($caseKeys, 0, 10)));
