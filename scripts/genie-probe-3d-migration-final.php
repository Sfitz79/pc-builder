<?php
// READ-ONLY: verify the rewritten 3D migration parses, list its DIMS keys, and
// report how many resolve against production Neon. Never writes.
$root = __DIR__ . '/..';
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env.production.neon';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim(trim($v), '"'));
    $_ENV[trim($k)] = trim(trim($v), '"');
}
putenv('APP_ENV=production');

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// PRODUCTION GUARD: reads .env.production.neon. Assert the driver before
// querying so a misconfigured run cannot report local sqlite as production.
if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: expected the pgsql (Neon) driver, got '" . DB::connection()->getDriverName() . "'. Refusing to report production data.\n");
    exit(2);
}

// Load the migration class and read its private const via reflection.
$path = $root . '/database/migrations/2026_09_23_120000_add_3d_dimensions_to_component_specs.php';
$migration = require $path;
$ref = new ReflectionClass($migration);

$dims = $ref->getConstant('DIMS');
$retired = $ref->getConstant('RETIRED_KEYS');

echo "DIMS_keys=" . count($dims) . "\n";
echo "RETIRED_KEYS=" . count($retired) . "\n\n";

$rows = DB::table('components')->whereIn('slug', array_keys($dims))->get(['id', 'slug', 'name', 'category_id', 'specs']);
echo "resolved_rows=" . $rows->count() . " of " . count($dims) . "\n\n";

$dimKeys = ['height', 'width', 'depth', 'length', 'thickness_slots', 'type', 'radiator_length', 'fan_count', 'glass_panels', 'rad_top', 'rad_front', 'form'];
foreach ($rows as $r) {
    $s = is_string($r->specs) && $r->specs ? json_decode($r->specs, true) : [];
    $s = is_array($s) ? $s : [];
    $pre = array_intersect_key($s, array_flip($dimKeys));
    echo "  id={$r->id} cat={$r->category_id} slug={$r->slug}\n";
    echo "    name={$r->name}\n";
    echo "    already_has_dim_keys=" . (empty($pre) ? 'no' : json_encode(array_keys($pre))) . "\n";
    echo "    will_write=" . json_encode($dims[$r->slug]) . "\n";
}

// Confirm retired keys really are dead in production.
$dead = DB::table('components')->whereIn('slug', $retired)->pluck('slug');
echo "\nretired_keys_resolving_to_rows=" . $dead->count() . " " . json_encode($dead) . "\n";
