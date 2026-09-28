<?php
// Genie 2026-09-28: quantify the catalogue identity problem.
//
// Found while building system presets: GPU / RAM / Storage names carry no model
// number, and `chipset` is null. So the price on those rows cannot be tied to a
// part we can name. This script measures the blast radius per category: a row
// is "identifiable" only if its name contains a family token (RTX, RX, GTX,
// ARC, DDR5, NVMe, TB, ...) - anything else is a price attached to an unknown
// part, which is exactly the hallucinated-spec risk the brand rules forbid.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tokens = [
    'GPU' => ['RTX', 'RX ', 'GTX', 'ARC', 'GEFORCE', 'RADEON', 'QUADRO', 'INTEL'],
    'RAM' => ['DDR5', 'DDR4', 'DDR3'],
    'Storage' => ['NVME', 'SSD', 'TB', ' M.2', 'SATA'],
    'CPU' => ['RYZEN', 'CORE', 'THREADRIPPER', 'INTEL', 'AMD'],
    'Motherboard' => ['B650', 'B550', 'X670', 'Z790', 'B760', 'A520', 'A620', 'X870', 'B850', 'H610', 'H670'],
    'PSU' => ['W', 'WATT'],
    'Case' => ['ATX', 'MATT', 'TEMPERED', 'MESH', 'T4', 'C28', 'C5', 'H1'],
    'Cooler' => ['AIO', 'TOWER', 'AIR', 'MM', 'AM', 'LC', 'NH-D'],
];

echo "=== catalogue identity audit (production) ===".PHP_EOL;
printf("%-13s %8s %10s %12s %10s".PHP_EOL, 'CATEGORY', 'ACTIVE', 'IDENTIFIED', 'UNIDENTIFIED', 'PCT');
$totAct = $totBad = 0;

foreach ($tokens as $catName => $toks) {
    $c = DB::table('categories')->where('name', $catName)->first();
    if (! $c) {
        continue;
    }
    $rows = DB::table('components')->where('category_id', $c->id)->where('active', true)->get(['id', 'name']);
    $bad = 0;
    foreach ($rows as $r) {
        $n = strtoupper((string) $r->name);
        $ok = false;
        foreach ($toks as $t) {
            if (str_contains($n, strtoupper($t))) {
                $ok = true;
                break;
            }
        }
        if (! $ok) {
            $bad++;
        }
    }
    $act = $rows->count();
    $totAct += $act;
    $totBad += $bad;
    printf("%-13s %8d %10d %12d %9s%%".PHP_EOL, $catName, $act, $act - $bad, $bad,
        $act ? number_format($bad / $act * 100, 1) : '0.0');
}
printf("%-13s %8d %10d %12d %9s%%".PHP_EOL, 'ALL', $totAct, $totAct - $totBad, $totBad,
    $totAct ? number_format($totBad / $totAct * 100, 1) : '0.0');

echo PHP_EOL."=== how many unidentifiable rows are actually priced? ===".PHP_EOL;
foreach (['GPU', 'RAM', 'Storage'] as $catName) {
    $c = DB::table('categories')->where('name', $catName)->first();
    $priced = DB::table('components')->where('category_id', $c->id)->where('active', true)
        ->whereNotNull('price')->where('price', '>', 0)->count();
    $top = DB::table('components')->where('category_id', $c->id)->where('active', true)
        ->whereNotNull('price')->where('price', '>', 0)
        ->orderByDesc('price')->limit(3)->get(['id', 'name', 'price']);
    echo "  {$catName}: {$priced} priced rows. Top 3:".PHP_EOL;
    foreach ($top as $r) {
        echo sprintf("     £%-9.2f #%-6d %s".PHP_EOL, $r->price, $r->id, $r->name).PHP_EOL;
    }
}
