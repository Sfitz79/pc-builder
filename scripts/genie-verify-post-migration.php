<?php
// POST-MIGRATION VERIFICATION (read-only). Confirms the pending migrations did
// what they claim: permission tables exist, columns exist, and exactly the 8
// targeted rows now carry dimension keys with no collateral damage.
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
// verifying, or a "successful" check could be against local sqlite.
if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: expected the pgsql (Neon) driver, got '" . DB::connection()->getDriverName() . "'. Refusing to verify.\n");
    exit(2);
}

echo "=== 1. migration ledger ===\n";
$pending = DB::table('migrations')->where('migration', 'like', '2026_09%')->orderBy('id')->pluck('migration');
foreach ($pending as $m) echo "  recorded: {$m}\n";

echo "\n=== 2. permission tables ===\n";
$schema = DB::connection()->getSchemaBuilder();
foreach (['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'] as $t) {
    echo "  {$t}: " . ($schema->hasTable($t) ? 'EXISTS' : 'MISSING') . "\n";
}

echo "\n=== 3. component columns ===\n";
foreach (['image_url', 'source_url', 'chipset', 'price_checked_at'] as $c) {
    echo "  components.{$c}: " . ($schema->hasColumn('components', $c) ? 'EXISTS' : 'MISSING') . "\n";
}

echo "\n=== 4. 3D dimension rows ===\n";
$path = $root . '/database/migrations/2026_09_23_120000_add_3d_dimensions_to_component_specs.php';
$ref = new ReflectionClass(require $path);
$dims = $ref->getConstant('DIMS');

$rows = DB::table('components')->whereIn('slug', array_keys($dims))->get(['id', 'slug', 'name', 'specs']);
$ok = 0;
foreach ($rows as $r) {
    $s = is_string($r->specs) && $r->specs ? json_decode($r->specs, true) : [];
    $s = is_array($s) ? $s : [];
    $expected = $dims[$r->slug];
    $good = true;
    foreach ($expected as $k => $v) {
        if (! array_key_exists($k, $s) || $s[$k] != $v) $good = false;
    }
    if ($good) $ok++;
    echo "  id={$r->id} " . ($good ? 'OK  ' : 'FAIL') . " {$r->slug}\n";
    if (! $good) {
        echo "        expected=" . json_encode($expected) . "\n";
        echo "        actual  =" . json_encode(array_intersect_key($s, $expected)) . "\n";
    }
}
echo "  correct={$ok}/" . count($rows) . " (expected " . count($dims) . ")\n";

echo "\n=== 5. collateral damage check ===\n";
$total = DB::table('components')->count();
echo "  total_components={$total} (baseline 2708)\n";
$emptySpecs = DB::table('components')->whereNull('specs')->count();
echo "  null_specs_rows={$emptySpecs}\n";
$broken = DB::table('components')->whereNotNull('specs')->where('specs', 'not like', '{%')->count();
echo "  malformed_specs_rows={$broken} (should be 0)\n";
$srcUrl = DB::table('components')->whereNotNull('source_url')->where('source_url', '<>', '')->count();
echo "  populated_source_url={$srcUrl}\n";
