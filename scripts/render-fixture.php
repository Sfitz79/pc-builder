<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/**
 * Picks one real, active component per category so a render test uses the same
 * path the storefront does.
 *
 * The endpoint deliberately IGNORES client-supplied names and specs and re-reads
 * everything from the database by id. That is correct - it stops a crafted
 * payload loading arbitrary components - but it also means a test cannot invent a
 * part. It has to use real ids, which is why this exists.
 *
 * Prefers a current-generation GPU so the scene exercises the tier-3 geometry
 * paths rather than the entry-tier shortcuts.
 */
$cats = ['gpu', 'motherboard', 'cpu', 'ram', 'storage', 'psu', 'case', 'cooler'];

$out = [];
foreach ($cats as $cat) {
    $q = DB::table('components')
        ->join('categories', 'components.category_id', '=', 'categories.id')
        ->where('categories.slug', $cat)
        ->where('components.active', true);

    if ($cat === 'gpu') {
        $q->where('components.specs->chipset', 'GeForce RTX 5080');
    }
    $r = (clone $q)->select('components.id', 'components.name', 'components.specs')->orderBy('components.id')->first();

    if (! $r) {
        $r = DB::table('components')
            ->join('categories', 'components.category_id', '=', 'categories.id')
            ->where('categories.slug', $cat)
            ->where('components.active', true)
            ->select('components.id', 'components.name', 'components.specs')
            ->orderBy('components.id')
            ->first();
    }

    if ($r) {
        // name AND specs, not just the id.
        //
        // BuildSceneService::dimsFor() calls PartDimensions::resolve($category,
        // $name, $specs), and several resolvers decide what an object IS from those
        // two values - coolerDims() reads the product NAME to tell a liquid AIO from
        // an air tower ("arctic liquid", "kraken", "aio"). Passing only an id meant
        // resolve() saw an empty name, never matched, and returned air-tower
        // dimensions for a 360mm AIO. Every harness that passed ids alone therefore
        // measured the wrong build entirely.
        $specs = json_decode((string) $r->specs, true);
        $out[$cat] = [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'specs' => is_array($specs) ? $specs : [],
        ];
        printf("  %-12s %-7d %s\n", $cat, $r->id, $r->name);
    } else {
        printf("  %-12s (none active)\n", $cat);
    }
}

$path = __DIR__ . '/../storage/app/render-fixture.json';
@mkdir(dirname($path), 0777, true);
file_put_contents($path, json_encode(['parts' => $out], JSON_PRETTY_PRINT));

echo "\nwrote " . basename($path) . " with " . count($out) . " categories\n";
