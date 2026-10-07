<?php

/**
 * BACKFILL the new identity columns from database/scraped/*.json.
 *
 * WHY
 * ---
 * The scraper already fetched CL latency, module layout, case fan count,
 * side-panel type, storage interface and the manufacturer part number. The
 * seeder discarded them, so 1,263 parts sit under ambiguous marketing names
 * and nothing in the database can tell two of them apart.
 *
 * This reads the ORIGINAL scrape files and fills the new columns. It never
 * invents a value: a field absent from the scrape stays NULL, because a
 * fabricated spec is worse than an honest gap.
 *
 * ROW MATCHING
 * ------------
 * By source_url, which is unique per scraped product. Falling back to the name
 * would be guesswork: the whole problem is that names are not unique.
 *
 * USAGE
 *   php scripts/backfill-identity.php dry-run
 *   php scripts/backfill-identity.php apply
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const BACKUP = __DIR__ . '/../database/backups/identity-columns-before.json';

function num($v): ?int
{
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return (int) $v;
    $d = preg_replace('/[^0-9.]/', '', (string) $v);
    return ($d !== '' && is_numeric($d)) ? (int) round((float) $d) : null;
}

/**
 * Manufacturer part number from the source product URL.
 *
 * CORRECTED 2026-10-07. The first attempt tokenised the path on "/" and took the
 * whole final segment, which is the complete descriptive slug (~60 chars) and
 * so never matched - mpn stayed NULL on every row.
 *
 * The part code is the LAST HYPHEN-DELIMITED TOKEN of that slug:
 *
 *   .../v-color-manta-xsky-rgb-32-gb-...-ddr5-6000-cl30-memory-tmxsal1660830kwk
 *                                                                  ^^^^^^^^^^^^
 *   .../corsair-vengeance-64-gb-...-ddr5-6000-cl30-memory-cmk64gx5m2b6000z30
 *                                                                 ^^^^^^^^^^
 *
 * This is not cosmetic. Those trailing codes are what separate products that
 * share a marketing name and near-identical specs:
 *
 *   cmk64gx5m2b6000z30  = black     cmk64gx5m2b6000c30 = white
 *   tmxsal1660830kwk / tmxsal1660830wwk, f5-6000j2636h32gx2-tz5nrw / ...tz5nr
 *
 * I had recorded such pairs as "the same product listed twice" and proposed
 * deactivating one of each. That was wrong, and following it would have deleted
 * real colour variants from a live catalogue. This is why the dry-run existed.
 */
function mpnFromUrl(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (! is_string($path) || $path === '') return null;

    $segs = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
    $last = end($segs);
    if (! is_string($last) || $last === '') return null;

    // The part code is the final hyphen-delimited token, not the whole slug.
    $tokens = array_values(array_filter(explode('-', $last), fn ($t) => $t !== ''));
    $code = (string) end($tokens);

    // Must look like a code: alphanumeric, at least one digit, 5-24 chars.
    // Rejects prose tokens such as "memory", "black", "warranty".
    if (! preg_match('/^(?=.*\d)[a-z0-9]{5,24}$/i', $code)) return null;

    return strtoupper($code);
}

$mode = $argv[1] ?? 'dry-run';

$files = ['ram' => 'ram.json', 'storage' => 'storage.json', 'gpu' => 'gpu.json',
          'case' => 'case.json', 'cooler' => 'cooler.json'];

$updates = [];   // id => [col => value]
$stats = [];

foreach ($files as $slug => $file) {
    $path = database_path('scraped/' . $file);
    if (! is_file($path)) {
        $stats[$slug] = 'no scrape file';
        continue;
    }
    $items = json_decode((string) file_get_contents($path), true);
    if (! is_array($items)) {
        $stats[$slug] = 'unreadable';
        continue;
    }

    $filled = ['memory_speed' => 0, 'memory_type' => 0, 'cas_latency' => 0,
               'module_config' => 0, 'interface' => 0, 'storage_type' => 0,
               'mpn' => 0, 'case_type' => 0, 'side_panel' => 0,
               'included_fans' => 0, 'radiator_size' => 0];

    // url -> id, resolved once per category.
    $byUrl = [];
    foreach (DB::table('components')->whereIn('category_id',
            DB::table('categories')->where('slug', $slug)->pluck('id'))
        ->where('active', true)->pluck('id', 'source_url') as $url => $id) {
        if ($url) $byUrl[(string) $url] = (int) $id;
    }

    foreach ($items as $item) {
        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '' || ! isset($byUrl[$url])) continue;
        $id = $byUrl[$url];
        $raw = is_array($item['specs'] ?? null) ? $item['specs'] : [];
        $row = [];

        $mpn = mpnFromUrl($url);
        if ($mpn !== null) $row['mpn'] = $mpn;

        if ($slug === 'ram') {
            $speed = trim((string) ($raw['speed'] ?? ''));
            if ($speed !== '') {
                $row['memory_speed'] = $speed;
                if (preg_match('/DDR\s?([345])/i', $speed, $m)) $row['memory_type'] = 'DDR' . $m[1];
            }
            $cas = num($raw['cASLatency'] ?? null);
            if ($cas !== null) $row['cas_latency'] = $cas;
            $mods = trim((string) ($raw['modules'] ?? ''));
            if ($mods !== '') $row['module_config'] = $mods;
        }
        if ($slug === 'storage') {
            $i = trim((string) ($raw['interface'] ?? ''));
            if ($i !== '') $row['interface'] = $i;
            $t = trim((string) ($raw['type'] ?? ''));
            if ($t !== '') $row['storage_type'] = $t;
        }
        if ($slug === 'case') {
            $t = trim((string) ($raw['type'] ?? ''));
            if ($t !== '') $row['case_type'] = $t;
            $p = trim((string) ($raw['sidePanel'] ?? ''));
            if ($p !== '') $row['side_panel'] = $p;
            $f = num($raw['includedFans'] ?? null);
            if ($f !== null) $row['included_fans'] = $f;
        }
        if ($slug === 'cooler') {
            $r = trim((string) ($raw['radiatorSize'] ?? ''));
            if ($r !== '') $row['radiator_size'] = $r;
        }

        if ($row === []) continue;
        $updates[$id] = array_merge($updates[$id] ?? [], $row);
        foreach (array_keys($row) as $k) $filled[$k]++;
    }

    $stats[$slug] = $filled;
}

echo str_repeat('=', 72) . PHP_EOL;
echo "IDENTITY BACKFILL - {$mode}" . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;
foreach ($stats as $slug => $st) {
    if (is_string($st)) { printf("%-8s %s%s", $slug, $st, PHP_EOL); continue; }
    $parts = [];
    foreach ($st as $col => $n) if ($n > 0) $parts[] = "$col=$n";
    printf("%-8s %4d row(s) matched; %s%s", $slug, count($updates), implode('  ', $parts) ?: 'nothing', PHP_EOL);
}
printf("TOTAL rows to update : %d%s", count($updates), PHP_EOL);

if ($mode === 'dry-run') {
    echo PHP_EOL . '--- sample ---' . PHP_EOL;
    foreach (array_slice($updates, 0, 5, true) as $id => $row) {
        printf("  #%-6d %s%s", $id, json_encode($row), PHP_EOL);
    }
    echo PHP_EOL . 'DRY RUN - nothing written.' . PHP_EOL;
    exit(0);
}

if ($mode === 'backup') {
    @mkdir(dirname(BACKUP), 0775, true);
    $ids = array_keys($updates);
    $rows = $ids ? DB::table('components')->whereIn('id', $ids)
        ->get(['id', 'name', 'memory_speed', 'memory_type', 'cas_latency', 'module_config',
               'interface', 'storage_type', 'mpn', 'case_type', 'side_panel',
               'included_fans', 'radiator_size'])->all() : [];
    file_put_contents(BACKUP, json_encode(['takenAt' => date('c'), 'rows' => $rows], JSON_PRETTY_PRINT));
    printf("backup written: %s (%d rows)%s", BACKUP, count($rows), PHP_EOL);
    exit(0);
}

if ($mode === 'apply') {
    $cols = ['memory_speed', 'memory_type', 'cas_latency', 'module_config', 'interface',
             'storage_type', 'mpn', 'case_type', 'side_panel', 'included_fans', 'radiator_size'];
    $n = 0;
    foreach ($updates as $id => $row) {
        DB::table('components')->where('id', $id)->update(array_intersect_key($row, array_flip($cols)));
        $n++;
    }
    printf("APPLIED %d row update(s).%s", $n, PHP_EOL);
    exit(0);
}

fwrite(STDERR, "Unknown mode '{$mode}'. Use dry-run | backup | apply." . PHP_EOL);
exit(1);