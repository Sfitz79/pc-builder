<?php
/**
 * Deactivates the 5 catalogue rows that cannot be given a real image, because they are
 * not retail products.
 *
 * BOSS APPROVED 2026-10-13 ("drop the 5 junk catalogue rows").
 *
 * WHY DEACTIVATE AND NOT DELETE
 * -----------------------------
 * `active` is reversible: setting it back to true restores the row. DELETE is not. These
 * rows may still be referenced by old build records or prebuilt fixtures, and a silent
 * hard delete would take those with it. Deactivating makes them unsellable and invisible
 * to customers today, and leaves the door open if one turns out to be wanted.
 *
 * THE EVIDENCE FOR EACH ROW (measured, not assumed)
 *   #39111  AMD Ryzen 5 7400      PCPP slug ends "oemt"  = OEM TRAY chip, no retail box image exists
 *   #39145  Intel Core i5-3330S   2012 mobile OEM part, retired
 *   #39153  Intel Core i3-4160    slug is "intel-cpu-cm8064601483644" = ENGINEERING SAMPLE part number
 *   #39155  Intel Pentium G4600   2013 Haswell, retired
 *   #40690  Intel DC P3608 4TB    GBP 5,705.09 (catalogue scrape said GBP 6,191.74) - a 4TB SSD is ~GBP300
 *
 * DRY RUN BY DEFAULT. Pass --commit to write.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const TARGETS = [39111, 39145, 39153, 39155, 40690];

$commit = in_array('--commit', $argv, true);
$backup = __DIR__ . '/backups/deactivated-nonretail-' . date('Ymd-His') . '.json';

$rows = DB::table('components')->whereIn('id', TARGETS)->get(['id', 'name', 'price', 'active', 'source_url']);

echo "=== TARGETS (" . count($rows) . " of " . count(TARGETS) . " found) ===\n";
foreach ($rows as $r) {
    printf("  #%d  %-34s GBP %9.2f  active=%s\n", $r->id, substr((string) $r->name, 0, 34), $r->price, $r->active ? 'true' : 'false');
    echo '        ' . substr((string) $r->source_url, 0, 96) . "\n";
}

if (count($rows) !== count(TARGETS)) {
    fwrite(STDERR, "\nABORT: expected " . count(TARGETS) . " rows, found " . count($rows) . ". Refusing to guess.\n");
    exit(1);
}

// $rows is an Illuminate Collection, NOT an array. The first version called array_filter()
// on it and died with a TypeError - a dry run that cannot complete is worse than no dry
// run, because the abort message is the only thing anyone sees before giving up.
$activeRows = $rows->filter(fn ($r) => $r->active)->values();
$already = $rows->count() - $activeRows->count();
if ($already > 0) {
    echo "\nnote: {$already} already inactive; those will be left alone.\n";
}

if (! $commit) {
    echo "\nDRY RUN - nothing written. Re-run with --commit to deactivate.\n";
    echo "Backup would be written to: $backup\n";
    exit(0);
}

// Backup FIRST, always, even for an additive-looking change.
if (! is_dir(dirname($backup))) {
    mkdir(dirname($backup), 0o777, true);
}
file_put_contents($backup, json_encode($rows, JSON_PRETTY_PRINT));
echo "\nbackup written: $backup\n";

$ids = $activeRows->map(fn ($r) => (int) $r->id)->values()->all();
if (! $ids) {
    echo 'nothing to do - all already inactive.';
    exit(0);
}

$updated = DB::table('components')->whereIn('id', $ids)->update([
    'active' => false,
    'updated_at' => now(),
]);
echo "deactivated {$updated} row(s)\n\n";

// VERIFY through the same read a customer hits.
$stillVisible = DB::table('components')
    ->join('categories', 'components.category_id', '=', 'categories.id')
    ->where('components.active', true)
    ->whereIn('components.id', TARGETS)
    ->count();
echo 'still visible to a customer : ' . $stillVisible . " (must be 0)\n";

$offline = DB::table('components')->where('active', true)->count();
echo "active catalogue rows now    : {$offline}\n";

$noImage = DB::table('components')->where('active', true)
    ->where(fn ($q) => $q->whereNull('image_url')->orWhere('image_url', ''))->count();
echo "active rows WITHOUT an image : {$noImage}\n";
echo $noImage === 0
    ? "\nIMAGE COVERAGE IS NOW 100% of active components.\n"
    : "\nstill not at 100% - " . $noImage . " remaining\n";

echo "\nROLLBACK (if ever needed):\n";
echo "  php artisan tinker --execute=\"DB::table('components')->whereIn('id', [" . implode(',', $ids) . "])->update(['active' => true]);\"\n";
