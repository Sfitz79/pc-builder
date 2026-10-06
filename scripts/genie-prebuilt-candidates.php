<?php

/**
 * Inspect the PRODUCTION catalogue (Neon) so pre-built systems can be built from
 * real components with real prices, never invented ones.
 *
 * A pre-built that cannot be assembled from live catalogue rows is a broken
 * pre-built, so the hard constraints measured here are:
 *   - the price anchors per category (the floor and ceiling of any ladder)
 *   - which real components actually exist at each tier
 *
 * Read-only. Writes nothing.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();


require __DIR__ . '/genie-prod-guard.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$driver = DB::connection()->getDriverName();
echo "connection: " . (config('database.default')) . " ($driver)\n\n";

foreach (['components', 'categories', 'presets', 'build_presets', 'systems', 'system_presets'] as $t) {
    printf("  %-16s %s\n", $t, Schema::hasTable($t) ? 'EXISTS' : 'MISSING');
}
echo "\n";

$activeCol = Schema::hasColumn('components', 'active') ? 'active' : null;
echo "=== categories ===\n";
foreach (DB::table('categories')->orderBy('id')->get() as $c) {
    $q = DB::table('components')->where('category_id', $c->id);
    if ($activeCol) {
        $q->where($activeCol, 1);
    }
    printf(
        "  %-3s %-14s %-10s active: %d\n",
        $c->id,
        substr((string) ($c->name ?? '?'), 0, 14),
        substr((string) ($c->type ?? '?'), 0, 10),
        $q->count()
    );
}

echo "\n=== price anchors per category (the ladder cannot sit outside these) ===\n";
foreach (DB::table('categories')->orderBy('id')->get() as $c) {
    $q = DB::table('components')->where('category_id', $c->id)->whereNotNull('price')->where('price', '>', 0);
    if ($activeCol) {
        $q->where($activeCol, 1);
    }
    $rows = $q->orderBy('price')->get();
    if ($rows->isEmpty()) {
        printf("  %-14s NO PRICED ROWS\n", $c->name);
        continue;
    }
    $lo = $rows->first();
    $hi = $rows->last();
    printf(
        "  %-14s n=%-5d cheapest GBP%s (%s)  dearest GBP%s (%s)\n",
        substr((string) $c->name, 0, 14),
        $rows->count(),
        number_format($lo->price, 0),
        substr((string) $lo->name, 0, 26),
        number_format($hi->price, 0),
        substr((string) $hi->name, 0, 26)
    );
}

echo "\n=== GPU ladder (a pre-built is mostly decided by its GPU) ===\n";
$gpuCat = DB::table('categories')->where('name', 'like', '%GPU%')->orWhere('name', 'like', '%Graphics%')->first();
if ($gpuCat) {
    $q = DB::table('components')->where('category_id', $gpuCat->id)->whereNotNull('price')->where('price', '>', 0);
    if ($activeCol) {
        $q->where($activeCol, 1);
    }
    foreach ($q->orderBy('price')->get() as $g) {
        $tier = $g->tier ?? null;
        $vram = $g->specs['vram'] ?? $g->specs['memory'] ?? '?';
        printf(
            "  %-5s %-30s GBP%-6s tier:%-4s vram:%-6s id:%s\n",
            '',
            substr((string) $g->name, 0, 30),
            number_format($g->price, 0),
            (string) $tier,
            (string) $vram,
            $g->id
        );
    }
}
