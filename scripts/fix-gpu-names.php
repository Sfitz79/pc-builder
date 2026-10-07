<?php

/**
 * GPU NAME DISAMBIGUATION - backup / dry-run / apply.
 *
 * WHY
 * ---
 * The scraper collapsed distinct SKUs onto marketing names, so the storefront
 * offered the SAME NAME many times for entirely different cards:
 *
 *   "Gigabyte GAMING OC"  x15  = RX 5060 ... RTX 5090, GBP 290 - 3,599
 *   "Asus PRIME OC"       x13
 *   "Asus TUF GAMING OC"  x11
 *
 * A customer cannot tell which card they are buying, and our own GBP 1,700
 * recommendation came back as `gpu: ASRock Challenger OC` - unshoppable. The
 * discriminator survived in specs.chipset and specs.memory.
 *
 * WHY APPEND RATHER THAN REORDER
 * ------------------------------
 * Reordering to "Gigabyte GeForce RTX 5080 GAMING OC" reads better but
 * rewrites the string wholesale, which is exactly what the tier regexes and
 * the image lookups key off. Appending is additive: every existing substring
 * still matches, so nothing that used to match stops matching.
 *
 * gpuPerformanceTier() builds its haystack from chipset column + specs.chipset
 * + name, so the chipset will now appear twice in that haystack. Every tier
 * rule is a regex /contains test, never an equality or a count, so a repeated
 * substring cannot change the verdict.
 *
 * WHAT IT REFUSES TO DO
 * ---------------------
 * It will NOT invent a differentiator. Where two rows are identical on every
 * field we have (name, chipset, vram) it leaves BOTH alone and reports them, because
 * a fabricated suffix ("(2)") would be a lie about a product, and a fabricated
 * spec is worse than an honest duplicate.
 *
 * USAGE
 *   php scripts/fix-gpu-names.php backup     write the full before-state
 *   php scripts/fix-gpu-names.php dry-run    report, change nothing
 *   php scripts/fix-gpu-names.php apply      apply the safe subset
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const BACKUP = __DIR__ . '/../database/backups/gpu-names-before.json';

/** Normalise a spec value into a name-safe fragment. */
function frag(?string $v): string
{
    $v = trim((string) $v);
    return $v === '' ? '' : preg_replace('/\s+/', ' ', $v);
}

/**
 * Build the proposed name for one row, given how many rows share its current
 * name. Returns null when we cannot improve it.
 */
function proposeName(string $name, array $specs, int $sameNameCount): ?string
{
    $base = trim($name);
    if ($sameNameCount < 2) {
        return null; // already unique on its own
    }

    $chip = frag($specs['chipset'] ?? null) ?: frag($specs['gpu_chipset'] ?? null);
    if ($chip === '') {
        return null; // nothing to append; leave it and report
    }

    // Already contains it (e.g. "ASRock Intel Arc A380 Challenger ITX")?
    if (stripos($base, $chip) !== false) {
        return null;
    }

    $withChip = $base . ' ' . $chip;

    // Two rows can share name AND chipset (4 x Gigabyte GAMING OC are all
    // RX 9060 XT). Add VRAM as the tiebreaker.
    return $withChip . ' ' . frag($specs['memory'] ?? $specs['vram'] ?? $specs['vram_gb'] ?? null);
}

$mode = $argv[1] ?? 'dry-run';

$rows = DB::table('components')
    ->join('categories', 'components.category_id', '=', 'categories.id')
    ->where('categories.slug', 'gpu')
    ->where('components.active', true)
    ->select('components.id', 'components.name', 'components.price', 'components.specs', 'components.image_url')
    ->orderBy('components.id')
    ->get();

$counts = $rows->countBy('name');

$plan = [];   // id => new name
$skip = [];   // reason => [names]
foreach ($rows as $r) {
    $n = (int) $counts[$r->name];
    if ($n < 2) {
        continue;
    }
    $specs = json_decode($r->specs ?? '{}', true) ?: [];
    $new = proposeName($r->name, $specs, $n);
    if ($new === null) {
        $skip['no usable discriminator'][] = $r->name;
        continue;
    }
    $plan[$r->id] = ['old' => $r->name, 'new' => $new, 'price' => (float) $r->price];
}

// SAFETY GATE: a rename is only allowed if it actually makes the name unique.
// Anything still colliding is dropped from the plan, never written.
$newCounts = [];
foreach ($plan as $id => $p) {
    $newCounts[$p['new']][] = $id;
}
$collide = [];
foreach ($newCounts as $newName => $ids) {
    if (count($ids) > 1) {
        foreach ($ids as $id) {
            $collide[$id] = $newName;
        }
    }
}
foreach ($collide as $id => $name) {
    $skip['proposed name still not unique (indistinguishable rows)'][] = $plan[$id]['old'];
    unset($plan[$id]);
}

echo str_repeat('=', 72) . PHP_EOL;
echo "GPU NAME DISAMBIGUATION - {$mode}" . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;
printf("active GPUs scanned      : %d%s", $rows->count(), PHP_EOL);
printf("ambiguous names          : %d (covering %d rows)%s", $rows->countBy('name')->filter(fn($c) => $c > 1)->count(), (int)array_sum(array_filter($rows->countBy('name')->all(), fn($c) => $c > 1)), PHP_EOL);
printf("SAFE renames planned     : %d%s", count($plan), PHP_EOL);
foreach ($skip as $reason => $list) {
    printf("LEFT ALONE (%s): %d row(s)%s", $reason, count($list), PHP_EOL);
    foreach (array_count_values($list) as $n => $c) {
        printf("    %-44s x%d%s", $n, $c, PHP_EOL);
    }
}

if ($mode === 'dry-run') {
    echo PHP_EOL . '--- first 15 proposed renames ---' . PHP_EOL;
    foreach (array_slice($plan, 0, 15, true) as $id => $p) {
        printf("  #%-6d GBP %-8s%s%s%s", $id, number_format($p['price']), $p['old'], '  ->  ', $p['new'], PHP_EOL);
    }
    echo PHP_EOL . 'DRY RUN - nothing was written.' . PHP_EOL;
    exit(0);
}

if ($mode === 'backup') {
    @mkdir(dirname(BACKUP), 0775, true);
    $payload = ['takenAt' => date('c'), 'rows' => $rows->map(fn($r) => (array) $r)->all()];
    file_put_contents(BACKUP, json_encode($payload, JSON_PRETTY_PRINT));
    printf("backup written: %s (%d rows, %.1f KB)%s", BACKUP, count($payload['rows']), filesize(BACKUP) / 1024, PHP_EOL);
    exit(0);
}

if ($mode === 'apply') {
    if (! is_file(BACKUP)) {
        fwrite(STDERR, "REFUSING TO APPLY - no backup at " . BACKUP . " Run the backup mode first." . PHP_EOL);
        exit(1);
    }
    $changed = 0;
    foreach ($plan as $id => $p) {
        DB::table('components')->where('id', $id)->update(['name' => $p['new']]);
        $changed++;
    }
    printf("APPLIED %d rename(s).%s", $changed, PHP_EOL);
    exit(0);
}

fwrite(STDERR, "Unknown mode '{$mode}'. Use backup | dry-run | apply." . PHP_EOL);
exit(1);