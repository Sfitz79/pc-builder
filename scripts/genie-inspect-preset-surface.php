<?php
// Genie 2026-09-28: what categories and preset concepts does the AI Builder
// already have? Boss directive: build pre-setup system presets (top 3 popular
// specs per category) WITH PRICES, grounded in the real catalogue.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$candidate = ['components', 'categories', 'presets', 'build_presets', 'systems', 'system_presets'];
foreach ($candidate as $t) {
    if (Schema::hasTable($t)) {
        echo str_pad($t, 16).'EXISTS  cols: '.implode(', ', Schema::getColumnListing($t)).PHP_EOL;
    } else {
        echo str_pad($t, 16)."MISSING".PHP_EOL;
    }
}

echo PHP_EOL.'--- categories (prod) ---'.PHP_EOL;
if (Schema::hasTable('categories')) {
    foreach (DB::table('categories')->orderBy('id')->get() as $c) {
        $n = DB::table('components')->where('category_id', $c->id)->where('active', true)->count();
        echo str_pad((string) $c->id, 4).str_pad((string) ($c->name ?? '?'), 16)
            .str_pad((string) ($c->type ?? '?'), 12)." active components: $n".PHP_EOL;
    }
} else {
    echo "no categories table".PHP_EOL;
}

// The cheapest and dearest real parts per category: the anchor points any
// preset ladder has to sit between. A preset that cannot be built from the
// live catalogue is a broken preset, so these are the hard constraint.
echo PHP_EOL.'--- price anchors per category (live, active, evidenced-or-not) ---'.PHP_EOL;
if (Schema::hasTable('components') && Schema::hasColumn('components', 'category_id')) {
    foreach (DB::table('categories')->orderBy('id')->get() as $c) {
        $rows = DB::table('components')
            ->where('category_id', $c->id)->where('active', true)
            ->whereNotNull('price')->where('price', '>', 0)
            ->orderBy('price')->get(['id', 'name', 'price', 'chipset']);
        if ($rows->isEmpty()) {
            continue;
        }
        $lo = $rows->first();
        $hi = $rows->last();
        echo sprintf(
            "%-14s n=%-4d cheapest £%-8.2f %-34s dearest £%-9.2f %s%s",
            $c->name, $rows->count(), $lo->price, mb_strimwidth((string) $lo->name, 0, 34),
            $hi->price, mb_strimwidth((string) $hi->name, 0, 40), PHP_EOL
        );
    }
}
