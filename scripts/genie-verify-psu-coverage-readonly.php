<?php
/**
 * READ-ONLY. Counts production PSU efficiency coverage so the backfill decision is
 * made on a VERIFIED number rather than the stale local-SQLite estimate.
 *
 * WHY THIS EXISTS. ScrapedCatalogSeeder now writes specs.efficiency_rating from
 * specs.efficiencyRating, which the scrape has always carried (3,245 of 3,669 rows,
 * 88.44%). The Neon catalogue has not been re-seeded, so this counts what is actually
 * there before anyone writes to production - the project's own rules forbid a
 * destructive write on an unverified count.
 *
 * NO WRITES. Read-only SELECTs only.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$psuId = DB::table('categories')->where('slug', 'psu')->value('id');
if (! $psuId) {
    fwrite(STDERR, "No 'psu' category found.\n");
    exit(1);
}

echo "=== PRODUCTION PSU COVERAGE (read-only) ===\n\n";

$rows = DB::select(
    "select c.id, c.name, c.wattage, c.specs
       from components c
      where c.category_id = ? and c.active = true",
    [$psuId]
);

$total = count($rows);
$withRating = 0;
$withAnySpecs = 0;
$nullSpecs = 0;
$values = [];
foreach ($rows as $r) {
    $specs = $r->specs;
    if ($specs === null) {
        $nullSpecs++;
        continue;
    }
    $specs = is_string($specs) ? json_decode($specs, true) : $specs;
    if (! is_array($specs) || $specs === []) {
        $nullSpecs++;
        continue;
    }
    $withAnySpecs++;
    $eff = $specs['efficiency_rating'] ?? null;
    if (is_string($eff) && trim($eff) !== '') {
        $withRating++;
        $values[trim($eff)] = ($values[trim($eff)] ?? 0) + 1;
    }
}

printf("active PSUs                    : %d\n", $total);
printf("specs IS NULL or empty         : %d\n", $nullSpecs);
printf("specs present (any key)       : %d\n", $withAnySpecs);
printf("specs.efficiency_rating set   : %d  (%.2f%% of active)\n", $withRating, $total ? ($withRating / $total) * 100 : 0);
printf("AFTER backfill would gain      : %d rows\n", $total - $withRating);

echo "\nExisting efficiency_rating values (pre-backfill, expect none):\n";
arsort($values);
if (! $values) {
    echo "  (none - as expected before the backfill)\n";
} else {
    foreach ($values as $v => $n) {
        printf("  %-16s %d\n", $v, $n);
    }
}

// What the backfill WOULD produce, from the scrape, without touching the database.
$scrape = __DIR__ . '/../database/scraped/power-supply.json';
if (is_file($scrape)) {
    $dump = json_decode((string) file_get_contents($scrape), true);
    $future = [];
    foreach ((array) $dump as $row) {
        $e = trim((string) ($row['specs']['efficiencyRating'] ?? ''));
        if ($e !== '') {
            $future[$e] = ($future[$e] ?? 0) + 1;
        }
    }
    arsort($future);
    echo "\nWhat the scrape would supply (full dump):\n";
    foreach ($future as $v => $n) {
        printf("  %-16s %d\n", $v, $n);
    }
}

echo "\nNO WRITES PERFORMED - read-only verification only.\n";