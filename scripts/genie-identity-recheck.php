<?php
// Genie 2026-09-28: BEFORE quarantining anything, re-test identifiability properly.
//
// My first identity test only looked at the `name`. That is too narrow: a RAM
// row titled "G.Skill Ripjaws X 8 GB" carries no DDR token, but its `specs`
// JSON probably holds type/capacity/speed. If so, RAM is not unidentifiable - it
// is merely unidentifiable *from the name*, and quarantining on the name test
// alone would strip the shop from 375 RAM options to zero and take the
// configurator down. This measures what specs actually contain.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== what does specs actually hold? (3 samples per category) ===".PHP_EOL;
foreach (['GPU', 'RAM', 'Storage', 'PSU', 'Case', 'Cooler'] as $catName) {
    $c = DB::table('categories')->where('name', $catName)->first();
    if (! $c) {
        continue;
    }
    echo "--- {$catName} ---".PHP_EOL;
    foreach (DB::table('components')->where('category_id', $c->id)->where('active', true)
        ->orderBy('price')->limit(3)->get() as $r) {
        $specs = json_decode((string) $r->specs, true);
        $keys = is_array($specs) ? array_keys($specs) : [];
        $flat = [];
        foreach ((array) $specs as $k => $v) {
            if (is_scalar($v) && $v !== '') {
                $flat[] = $k.'='.mb_strimwidth((string) $v, 0, 22);
            }
        }
        echo sprintf("  £%-8.2f #%-6d %-34s | %d keys | %s", $r->price, $r->id,
            mb_strimwidth((string) $r->name, 0, 34), count($keys),
            $flat ? implode(' ', array_slice($flat, 0, 7)) : '(specs empty)').PHP_EOL;
    }
}

// How many rows per category are recoverable from specs alone?
echo PHP_EOL."=== identifiability using name + chipset + specs (not name alone) ===".PHP_EOL;
$rules = [
    'GPU' => ['name' => ['RTX', 'RX ', 'GTX', 'ARC', 'GEFORCE', 'RADEON'],
        'specs' => ['chipset', 'gpu_chipset', 'model', 'gpu_model', 'vram', 'memory']],
    'RAM' => ['name' => ['DDR'], 'specs' => ['type', 'memory_type', 'capacity', 'size', 'speed', 'modules']],
    'Storage' => ['name' => ['NVME', 'SSD', 'TB', 'SATA'],
        'specs' => ['capacity', 'size', 'interface', 'type', 'form_factor']],
    'PSU' => ['name' => ['W'], 'specs' => ['wattage', 'power', 'rating', 'efficiency']],
    'Case' => ['name' => [], 'specs' => ['form_factor', 'type', 'motherboard_support']],
    'Cooler' => ['name' => ['AIO', 'TOWER', 'AIR'], 'specs' => ['type', 'height', 'tdp', 'radiator_size']],
];

printf("%-12s %8s %12s %14s %10s".PHP_EOL, 'CATEGORY', 'ACTIVE', 'RECOVERABLE', 'STILL-UNKNOWN', 'PCT-UNK');
foreach ($rules as $catName => $rule) {
    $c = DB::table('categories')->where('name', $catName)->first();
    $rows = DB::table('components')->where('category_id', $c->id)->where('active', true)->get(['name', 'chipset', 'specs']);
    $unknown = 0;
    foreach ($rows as $r) {
        $hay = strtoupper(trim((string) $r->name.' '.(string) $r->chipset));
        $ok = false;
        foreach ($rule['name'] as $t) {
            if (str_contains($hay, strtoupper($t))) { $ok = true; break; }
        }
        if (! $ok) {
            $specs = json_decode((string) $r->specs, true);
            foreach ($rule['specs'] as $k) {
                if (is_array($specs) && ! empty($specs[$k])) { $ok = true; break; }
            }
        }
        if (! $ok) { $unknown++; }
    }
    printf("%-12s %8d %12d %14d %9s%%".PHP_EOL, $catName, $rows->count(),
        $rows->count() - $unknown, $unknown,
        $rows->count() ? number_format($unknown / $rows->count() * 100, 1) : '0.0');
}
