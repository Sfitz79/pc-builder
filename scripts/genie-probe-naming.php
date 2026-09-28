<?php
// Diagnostic: what do GPU / RAM / Storage rows actually look like?
// The preset resolver returned NO MATCH for those three categories while
// matching CPU and motherboard fine, which points at the naming convention
// rather than at an empty category. Confirm before assuming anything.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['GPU', 'RAM', 'Storage'] as $name) {
    $c = DB::table('categories')->where('name', $name)->first();
    if (! $c) {
        continue;
    }
    echo "=== $name (cat {$c->id}) — 12 cheapest, then chipset/specs sample ===".PHP_EOL;

    foreach (DB::table('components')->where('category_id', $c->id)->where('active', true)
        ->whereNotNull('price')->where('price', '>', 0)->orderBy('price')->limit(12)->get() as $r) {
        echo sprintf("  £%-8.2f #%-6d %-46s | chipset=%s".PHP_EOL, $r->price, $r->id,
            mb_strimwidth((string) $r->name, 0, 46), $r->chipset ?? '-');
    }

    // Do modern parts exist at all? Count by a loose year-ish token.
    $total = DB::table('components')->where('category_id', $c->id)->where('active', true)->count();
    echo "  active total: $total".PHP_EOL;

    // Show how many mention the current-generation token anywhere.
    foreach (['RTX 50', 'RTX 40', 'RX 90', 'RX 78', 'DDR5', '2TB', '1TB', '1 TB', '1000GB'] as $tok) {
        $n = DB::table('components')->where('category_id', $c->id)->where('active', true)
            ->where('name', 'ILIKE', "%{$tok}%")->count();
        if ($n) {
            echo "  name contains '{$tok}': {$n}".PHP_EOL;
        }
    }
    echo PHP_EOL;
}
