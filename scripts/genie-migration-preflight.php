<?php
// Genie 2026-09-28: pre-flight check for the 5 pending production migrations.
// Each migration carries an idempotency guard for columns that were added to
// production out-of-band (Neon pooled DDL-in-transaction blocked them). This
// confirms the guards will actually skip, rather than assume it.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$components = Schema::getColumnListing('components');
printf('components has %d columns'.PHP_EOL, count($components));

$expected = [
    '2026_09_02_000004_add_image_and_source_url' => ['source_url', 'image_url'],
    '2026_09_02_141136_add_chipset' => ['chipset'],
    '2026_09_27_190000_add_price_checked_at' => ['price_checked_at'],
];

echo PHP_EOL.'=== per-migration column pre-existence ==='.PHP_EOL;
foreach ($expected as $migration => $cols) {
    foreach ($cols as $col) {
        printf('  %-14s %-18s : %s'.PHP_EOL, substr($migration, 5, 14), $col, in_array($col, $components, true) ? 'EXISTS (guard will skip)' : 'absent (migration will add)');
    }
}

echo PHP_EOL.'=== 3d_dimensions migration ==='.PHP_EOL;
$m = file_get_contents(__DIR__.'/../database/migrations/2026_09_23_120000_add_3d_dimensions_to_component_specs.php');
printf('  targets specs JSON?     %s'.PHP_EOL, str_contains($m, 'specs') ? 'yes' : 'no');
printf('  idempotency guard?     %s'.PHP_EOL, str_contains($m, 'hasColumn') || str_contains($m, 'getColumnListing') ? 'yes' : 'NO GUARD - will re-run DDL');

echo PHP_EOL.'=== permission tables migration ==='.PHP_EOL;
$p = file_get_contents(__DIR__.'/../database/migrations/2026_09_07_201642_create_permission_tables.php');
preg_match_all("/create\('([a-z_]+)'/", $p, $m2);
printf('  creates tables: %s'.PHP_EOL, implode(', ', $m2[1]));
foreach (array_unique($m2[1]) as $t) {
    printf('    %-26s %s'.PHP_EOL, $t, Schema::hasTable($t) ? 'EXISTS (would fail!)' : 'absent (safe to create)');
}
