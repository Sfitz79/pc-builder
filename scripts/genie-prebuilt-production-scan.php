<?php

/**
 * Build candidate PRE-BUILT systems from the PRODUCTION catalogue (Neon).
 *
 * Rules this script enforces, because a published pre-built is a customer promise:
 *   - READ-ONLY against production. Writes nothing.
 *   - Only ACTIVE, realistically-priced rows. The catalogue contains corrupt
 *     bundle-priced rows (a GBP17,635 SSD, GBP6,562 RAM) that the price
 *     integrity pass quarantines; publishing one would be a brand-integrity
 *     failure, so anything outside a sane band per category is dropped.
 *   - Only rows we can actually identify (real model name).
 *   - Every returned component carries its id, name and price so the page can
 *     show a genuine total rather than an invented one.
 */

$root = dirname(__DIR__);

// Point the app at production Neon using the local-only env file. This MUST
// happen before the app bootstraps: a previous version of this script merely
// READ that file and then silently queried the local SQLite database instead,
// which is exactly the "local SQLite is not production Postgres" trap.
$envFile = $root . '/.env.production.neon';
if (!is_readable($envFile)) {
    fwrite(STDERR, "cannot read $envFile\n");
    exit(1);
}

$applied = 0;
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
    $applied++;
}
fwrite(STDERR, "applied $applied env vars from .env.production.neon\n");

require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

printf("driver: %s\n", DB::connection()->getDriverName());

// Refuse to report anything if we are not actually on production Postgres. A
// silent fallback to local SQLite here would mean publishing local catalogue
// prices as if they were the live ones.
if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: not connected to Postgres. Refusing to report catalogue data.\n");
    exit(2);
}
printf("components rows: %d active\n", DB::table('components')->where('active', 1)->count());

// Sanity-check the production price integrity gates before trusting any price.
echo "\n=== price integrity config (production) ===\n";
printf(
    "  enforce_freshness: %s\n",
    var_export((bool) config('price_integrity.enforce_freshness'), true)
);
printf(
    "  max_component_price: %s\n",
    var_export(config('price_integrity.max_component_price'), true)
);

echo "\n=== components per category, sane price band only ===\n";

$sane = [
    'CPU' => [30, 1200],
    'CPU Cooler' => [8, 250],
    'Motherboard' => [40, 700],
    'GPU' => [120, 2200],
    'RAM' => [15, 400],
    'Storage' => [20, 500],
    'PSU' => [25, 300],
    'Case' => [20, 400],
];

$out = [];
foreach (DB::table('categories')->orderBy('id')->get() as $c) {
    $name = (string) $c->name;
    $band = $sane[$name] ?? null;
    if ($band === null) {
        printf("  %-12s NO BAND - skipped\n", $name);
        continue;
    }

    $q = DB::table('components')
        ->where('category_id', $c->id)
        ->where('active', 1)
        ->whereNotNull('price')
        ->where('price', '>', $band[0])
        ->where('price', '<=', $band[1])
        ->whereNotNull('name');

    $rows = $q->orderBy('price')->get();
    printf("  %-12s usable: %4d of %d active (band GBP%d-%d)\n", $name, $rows->count(), DB::table('components')->where('category_id', $c->id)->where('active', 1)->count(), $band[0], $band[1]);

    $out[$name] = $rows->map(function ($r) {
        // Rule 1: identify the exact reader and measure EVERY field it could
        // read. The dedicated `chipset` COLUMN is 0/249 populated in production
        // while `specs.chipset` is 249/249. Reading only the column reports 0
        // identifiable GPUs when every single one is recoverable, and that
        // false measurement is what nearly caused a destructive write on
        // 2026-09-28. Record which source won so the evidence stays auditable.
        $specs = $r->specs ?? null;
        if (is_string($specs)) {
            $specs = json_decode($specs, true);
        }
        $specs = is_array($specs) ? $specs : [];

        $fromColumn = trim((string) ($r->chipset ?? ''));
        $fromSpecs = trim((string) ($specs['chipset'] ?? ''));
        $chipset = $fromColumn !== '' ? $fromColumn : $fromSpecs;

        return [
            'id' => $r->id,
            'name' => $r->name,
            'price' => (float) $r->price,
            'chipset' => $chipset !== '' ? $chipset : null,
            'chipset_source' => $fromColumn !== '' ? 'column' : ($fromSpecs !== '' ? 'specs' : 'none'),
        ];
    })->all();

    // Make the evidence visible instead of asserting it.
    if ($name === 'GPU') {
        $src = ['column' => 0, 'specs' => 0, 'none' => 0];
        foreach ($out['GPU'] as $g) {
            $src[$g['chipset_source']]++;
        }
        printf(
            "  %-12s chipset source: column=%d specs=%d unidentified=%d  (column alone would have claimed %d missing)\n",
            'GPU',
            $src['column'],
            $src['specs'],
            $src['none'],
            $src['specs'] + $src['none']
        );
    }
}

file_put_contents(
    $root . '/database/scraped/prebuilt-candidates.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\nwrote database/scraped/prebuilt-candidates.json\n";

// Show the mid-tier GPU options, which is what decides each pre-built.
echo "\n=== realistic GPU options (GBP200-900, the core of any pre-built) ===\n";
foreach ($out['GPU'] ?? [] as $g) {
    if ($g['price'] < 200 || $g['price'] > 900) {
        continue;
    }
    printf("  %-5s %-38s GBP%-7s %s\n", $g['id'], substr($g['name'], 0, 38), number_format($g['price'], 0), $g['chipset'] ?? '');
}
