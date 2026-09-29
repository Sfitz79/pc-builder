<?php
// PRE-WRITE SNAPSHOT (this one DOES write, but only to a local file - it does
// not touch the database). Captures the exact pre-migration state of the 8 rows
// the 3D backfill will modify, so the change can be verified or reversed even
// though the git history is corrupt.
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

$path = $root . '/database/migrations/2026_09_23_120000_add_3d_dimensions_to_component_specs.php';
$ref = new ReflectionClass(require $path);
$dims = $ref->getConstant('DIMS');

$rows = DB::table('components')->whereIn('slug', array_keys($dims))
    ->get(['id', 'slug', 'name', 'category_id', 'specs']);

$snapshot = [
    'captured_at' => gmdate('c'),
    'purpose' => 'Pre-migration snapshot for 2026_09_23_120000_add_3d_dimensions_to_component_specs',
    'db_host' => DB::connection()->getConfig()['host'] ?? '?',
    'rows' => $rows->map(fn ($r) => [
        'id' => $r->id,
        'slug' => $r->slug,
        'name' => $r->name,
        'category_id' => $r->category_id,
        'specs' => $r->specs,
    ])->all(),
];

$outDir = $root . '/database/snapshots';
if (! is_dir($outDir)) mkdir($outDir, 0755, true);
$outFile = $outDir . '/pre-3d-dims-' . gmdate('Ymd-His') . '.json';
file_put_contents($outFile, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "snapshotted_rows=" . count($snapshot['rows']) . "\n";
echo "written_to=" . $outFile . "\n";
