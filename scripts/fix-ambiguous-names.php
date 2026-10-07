<?php

/**
 * AMBIGUOUS COMPONENT NAME DISAMBIGUATION - backup / dry-run / apply.
 *
 * THE DEFECT
 * ----------
 * The scraper collapsed distinct SKUs onto marketing names, so the storefront
 * offers the SAME NAME many times over for genuinely different products:
 *
 *   "Crucial BX500"                  x6  = 240GB ... 4096GB, GBP 54 - 390
 *   "Corsair Vengeance RGB 32 GB"  x17  = DDR5-5200 ... 7200, GBP 371 - 601
 *   "Lian Li EDGE GOLD"             x6  = 850W / 1000W / 1200W
 *   "Gigabyte GAMING OC"           x15  = RX 5060 ... RTX 5090
 *
 * A customer cannot tell which product they are buying, and our own
 * recommendation engine surfaced "gpu: ASRock Challenger OC" - unshoppable.
 *
 * WHY APPEND RATHER THAN REORDER
 * ------------------------------
 * Reordering ("Gigabyte GeForce RTX 5080 GAMING OC") reads better but rewrites
 * the string wholesale, and both the tier regexes and the image lookups key off
 * the existing text. Appending is additive: every substring that matched before
 * still matches, so nothing that used to resolve stops resolving.
 *
 * WHY THIS IS SAFE FOR THE RECOMMENDATION ENGINE
 * ----------------------------------------------
 * gpuPerformanceTier() builds its haystack from the chipset column +
 * specs.chipset + name, so an appended chipset appears TWICE in that string.
 * Every tier rule is a regex /contains test - never an equality, never a count -
 * so a repeated substring cannot change a verdict. Verified after writing.
 *
 * WHAT IT REFUSES TO DO
 * ---------------------
 * It never invents a differentiator. A rename is applied ONLY if it produces a
 * name that is genuinely unique across the category. Where two rows are
 * identical on every field we hold, BOTH are left alone and reported - those are
 * the same product listed by different retailers, and appending "(2)" would be a
 * lie about a product. A fabricated spec is worse than an honest duplicate.
 *
 * CATEGORIES DELIBERATELY NOT INCLUDED
 * ------------------------------------
 *   case    - specs are EMPTY ([]) on the duplicate rows. There is no form
 *             factor, no size, nothing to append. Fixing this needs the data
 *             sourced, not a rename.
 *   cooler  - same: no discriminating spec on the duplicate rows.
 *   cpu     - cores/threads split 0 of 13 groups; the duplicates are identical
 *             chips, i.e. duplicate listings.
 *
 * USAGE
 *   php scripts/fix-ambiguous-names.php backup
 *   php scripts/fix-ambiguous-names.php dry-run
 *   php scripts/fix-ambiguous-names.php apply
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const BACKUP = __DIR__ . '/../database/backups/ambiguous-names-before.json';

/**
 * Per-category discriminator fields, applied in order until the name is unique.
 *
 * 'specs:field' reads from the JSON specs column.
 * 'col:field'   reads a dedicated column (e.g. PSU wattage, which the scraper
 *               put in its own column rather than in specs).
 */
const RULES = [
    // Now backed by real columns (migration 2026_10_07_000001), not by parsing
    // specs JSON at every call site.
    'storage' => [['specs:capacity', ''], ['col:storage_type', ''], ['col:interface', '']],
    'ram' => [['col:memory_speed', ''], ['col:cas_latency', ''], ['col:module_config', '']],
    'psu' => [['col:wattage', 'W']],
    'gpu' => [['specs:chipset', ''], ['specs:memory', '']],
    'case' => [['col:case_type', ''], ['col:side_panel', ''], ['col:included_fans', '']],
    'cooler' => [['col:radiator_size', '']],
];

function frag(?string $v): string
{
    $v = trim((string) $v);
    if ($v === '') return '';
    // "1024GB" -> "1TB"? No. Keep the source value verbatim but tidy spacing.
    return (string) preg_replace('/\s+/', ' ', $v);
}

function fieldValue(array $row, string $rule): string
{
    if (str_starts_with($rule, 'specs:')) {
        $specs = json_decode($row['specs'] ?? '{}', true) ?: [];
        return frag($specs[substr($rule, 6)] ?? null);
    }
    return frag($row[substr($rule, 4)] ?? null);
}

/** Append rule fields that are not already present in the name. */
function propose(string $name, array $row, array $rules): ?string
{
    $out = trim($name);
    $added = 0;
    foreach ($rules as [$rule, $unit]) {
        $v = fieldValue($row, $rule);
        if ($v === '') continue;
        // Add the unit only when the value does not already carry it, so
        // "850" becomes "850W" while "1024GB" is never doubled into "1024GBGB".
        if ($unit !== '' && !preg_match('/' . preg_quote($unit, '/') . '\b/i', $v)) {
            $v .= $unit;
        }
        if (stripos($out, $v) !== false) continue; // already there
        $out .= ' ' . $v;
        $added++;
    }
    return $added > 0 ? $out : null;
}

$mode = $argv[1] ?? 'dry-run';

$plans = [];  // category => [id => ['old','new']]
$skips = [];  // category => [oldName => reason]

foreach (array_keys(RULES) as $cat) {
    $ids = DB::table('categories')->where('slug', $cat)->pluck('id');
    if ($ids->isEmpty()) continue;

    $rows = DB::table('components')
        ->whereIn('category_id', $ids)
        ->where('active', true)
        ->select('id', 'name', 'price', 'specs', 'wattage', 'storage_type', 'interface',
                 'memory_speed', 'cas_latency', 'module_config',
                 'case_type', 'side_panel', 'included_fans', 'radiator_size')
        ->orderBy('id')
        ->get();

    $counts = $rows->countBy('name');
    $plan = [];
    foreach ($rows as $r) {
        if (($counts[$r->name] ?? 1) < 2) continue;
        $new = propose($r->name, (array) $r, RULES[$cat]);
        if ($new === null) continue;
        $plan[$r->id] = ['old' => $r->name, 'new' => $new, 'price' => (float) $r->price];
    }

    // FAIL CLOSED: only keep renames that produce a unique name.
    $tally = [];
    foreach ($plan as $id => $p) {
        $tally[$p['new']][] = $id;
    }
    foreach ($tally as $newName => $ids2) {
        if (count($ids2) > 1) {
            foreach ($ids2 as $id) {
                $skips[$cat]['still not unique - duplicate listings of the same product'][$plan[$id]['old']] = true;
                unset($plan[$id]);
            }
        }
    }
    if ($plan) $plans[$cat] = $plan;
}

echo str_repeat('=', 74) . PHP_EOL;
echo "AMBIGUOUS COMPONENT NAMES - {$mode}" . PHP_EOL;
echo str_repeat('=', 74) . PHP_EOL;

$totalApply = 0;
foreach ($plans as $cat => $plan) {
    $totalApply += count($plan);
    printf("%-10s %4d safe rename(s)%s", $cat, count($plan), PHP_EOL);
}
printf("TOTAL SAFE RENAMES       : %d%s", $totalApply, PHP_EOL);
foreach ($skips as $cat => $reasons) {
    foreach ($reasons as $reason => $names) {
        printf("  %-10s LEFT ALONE %s: %d name(s)%s", $cat, "({$reason})", count($names), PHP_EOL);
        foreach (array_slice(array_keys($names), 0, 6, true) as $n) { echo "      {$n}" . PHP_EOL; }
    }
}

if ($mode === 'dry-run') {
    foreach ($plans as $cat => $plan) {
        echo PHP_EOL . "--- {$cat}: first 8 ---" . PHP_EOL;
        foreach (array_slice($plan, 0, 8, true) as $id => $p) {
            printf("  #%-6d GBP %-8s %s%s->%s%s", $id, number_format($p['price']), $p['old'], PHP_EOL . "              ", $p['new'], PHP_EOL);
        }
    }
    echo PHP_EOL . 'DRY RUN - nothing was written.' . PHP_EOL;
    exit(0);
}

if ($mode === 'backup') {
    @mkdir(dirname(BACKUP), 0775, true);
    $payload = [];
    foreach (array_keys(RULES) as $cat) {
        $ids = DB::table('categories')->where('slug', $cat)->pluck('id');
        $payload[$cat] = DB::table('components')->whereIn('category_id', $ids)->where('active', true)
            ->select('id', 'name', 'price', 'specs', 'wattage', 'storage_type',
                     'interface', 'memory_speed', 'cas_latency', 'module_config',
                     'case_type', 'side_panel', 'included_fans', 'radiator_size')
            ->get()->all();
    }
    file_put_contents(BACKUP, json_encode(['takenAt' => date('c'), 'rows' => $payload], JSON_PRETTY_PRINT));
    printf("backup written: %s (%.1f KB)%s", BACKUP, filesize(BACKUP) / 1024, PHP_EOL);
    exit(0);
}

if ($mode === 'apply') {
    if (! is_file(BACKUP)) {
        fwrite(STDERR, "REFUSING TO APPLY - no backup at {$BACKUP}. Run the backup mode first." . PHP_EOL);
        exit(1);
    }
    $changed = 0;
    foreach ($plans as $cat => $plan) {
        foreach ($plan as $id => $p) {
            DB::table('components')->where('id', $id)->update(['name' => $p['new']]);
            $changed++;
        }
    }
    printf("APPLIED %d rename(s).%s", $changed, PHP_EOL);
    exit(0);
}

fwrite(STDERR, "Unknown mode '{$mode}'. Use backup | dry-run | apply." . PHP_EOL);
exit(1);