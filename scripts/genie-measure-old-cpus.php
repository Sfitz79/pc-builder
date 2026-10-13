<?php

/**
 * Why are Ryzen 3xxx-series CPUs still reaching customers?
 *
 * Measures the CATALOGUE first (how many old Ryzen parts are still active), because the
 * selection logic can only pick what is active. Then reports the newest generations present,
 * so we can see whether this is "a few stragglers" or "the whole CPU category is stale".
 *
 * Read-only. Touches nothing.
 *
 * Usage: php scripts/genie-measure-old-cpus.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "CONNECTION: ".config('database.default')."\n";
try {
    DB::connection()->getPdo();
    echo "REACHABLE: yes\n\n";
} catch (\Throwable $e) {
    echo 'REACHABLE: NO - '.$e->getMessage()."\n\n";
    exit(1);
}

/**
 * Generation ladder for AMD Ryzen desktop CPUs. Anything below Ryzen 5000 is pre-Zen3:
 * 4xxx and below are genuinely obsolete for a new gaming build in 2026.
 *
 * MATCHES ONLY REAL RYZEN NAMES, ON PURPOSE.
 * The first version of this script fell back to a bare /(\d{4})/ when "Ryzen" was absent,
 * and that classified "Intel Core i5-10400" as series "1040" and reported ~40 healthy
 * current-generation Intel CPUs as obsolete. A classifier that invents generations is
 * worse than no classifier, so anything not explicitly a Ryzen desktop part is reported
 * separately and never counted.
 */
function seriesOf(string $name): ?string
{
    // Ryzen 5 3600 / Ryzen 3 3200G / Ryzen 7 5800X / Ryzen 9 7950X / Ryzen 5 4500
    if (preg_match('/ryzen\s+\d\s+(\d{4})/i', $name, $m)) {
        return 'Ryzen '.$m[1];
    }
    // Ryzen (no tier digit) e.g. "AMD Ryzen 9 9950X3D" is covered above; "Ryzen 7 9800X3D" too.

    return null; // not a Ryzen desktop part - counted separately, never aged
}

$cpus = DB::table('components')
    ->join('categories', 'components.category_id', '=', 'categories.id')
    ->where('categories.slug', 'cpu')
    ->where('components.active', true)
    ->orderBy('components.name')
    ->get(['components.id', 'components.name', 'components.price']);

echo 'ACTIVE CPUs IN CATALOGUE: '.$cpus->count()."\n\n";

$bySeries = [];
$notRyzen = [];
foreach ($cpus as $c) {
    $s = seriesOf($c->name);
    if ($s === null) {
        $notRyzen[] = $c;
    } else {
        $bySeries[$s][] = $c;
    }
}
ksort($bySeries);

echo 'ACTIVE CPUs IN CATALOGUE: '.$cpus->count()."\n";
echo '  of which AMD Ryzen (classifiable): '.array_sum(array_map('count', $bySeries))."\n";
echo '  not a Ryzen desktop name (left alone, e.g. Intel): '.count($notRyzen)."\n\n";

echo "ACTIVE AMD RYZEN COUNT BY GENERATION\n";
foreach ($bySeries as $series => $rows) {
    $n = count($rows);
    $flag = '';
    if (preg_match('/(\d{4})/', $series, $m) && (int) $m[1] < 5000) {
        $flag = '   <-- PRE-ZEN3, OBSOLETE FOR A NEW BUILD';
    }
    echo "  ".$series.' : '.$n.$flag."\n";
}

echo "\nEVERY ACTIVE PRE-ZEN3 RYZEN CPU (each one is currently offerable to a customer)\n";
$obsolete = 0;
foreach ($bySeries as $series => $rows) {
    if (! preg_match('/(\d{4})/', $series, $m) || (int) $m[1] >= 5000) {
        continue;
    }
    foreach ($rows as $c) {
        $obsolete++;
        echo '  '.str_pad((string) $c->id, 7).'  '.$c->name."\n";
    }
}

$modern = 0;
foreach ($bySeries as $series => $rows) {
    if (preg_match('/(\d{4})/', $series, $m) && (int) $m[1] >= 5000) {
        $modern += count($rows);
    }
}

echo "\nACTIVE RYZEN AT ZEN3 OR NEWER (5000+): $modern\n";
echo "ACTIVE RYZEN BELOW ZEN3 (pre-5000): $obsolete\n";
echo 'ACTIVE NON-RYZEN CPUs (NOT judged obsolete, listed for transparency): '.count($notRyzen)."\n";
$intelSample = array_slice($notRyzen, 0, 6);
foreach ($intelSample as $c) {
    echo '  e.g. '.$c->name."\n";
}
