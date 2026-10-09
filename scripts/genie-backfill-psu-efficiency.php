<?php
/**
 * PSU efficiency backfill for the PRODUCTION Neon catalogue.
 *
 * ScrapedCatalogSeeder now writes specs.efficiency_rating from the scrape's
 * nested specs.efficiencyRating (both were thrown away before). Production was
 * never re-seeded, so 287 active PSUs sit without a rating while 3,245 of the
 * 3,669 scraped rows carry a real one. This script backfills that single field
 * onto the live rows, additive and reversible - it never deletes or overwrites
 * a rating that is already present, and it dumps a backup of every specs
 * object it is about to change BEFORE the first write.
 *
 * SAFETY:
 *  - Defaults to DRY-RUN. Pass --write to make changes.
 *  - Requires the genie-prod-guard (driver must be pgsql - refuses SQLite/local).
 *  - Matches rows by the exact slug the seeder computes (Str::slug(productName)
 *    . '-' . substr(md5(url), 0, 6)), so identity is the same key the catalogue
 *    was seeded under - NOT a fuzzy store-name match.
 *  - Reads specs.efficiencyRating from the nested specs object only. The
 *    top-level `rating` field is the retailer review score and is never used.
 *  - Writes a JSON backup under scripts/backups/ before any UPDATE.
 *
 * USAGE:
 *   php scripts/genie-backfill-psu-efficiency.php            # dry run
 *   php scripts/genie-backfill-psu-efficiency.php --write    # backup + write
 *   php scripts/genie-backfill-psu-efficiency.php --rollback <file>
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Component;

$write = in_array('--write', $argv, true);
$rollbackArg = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--rollback' && isset($argv[$i + 1])) {
        $rollbackArg = $argv[$i + 1];
    }
}

$host = (string) (config('database.connections.' . DB::connection()->getDriverName() . '.host') ?? '?');
echo "=== PSU EFFICIENCY BACKFILL (production) ===\n";
echo "driver : " . DB::connection()->getDriverName() . "\n";
echo "host   : {$host}\n";
echo "mode   : " . ($write ? "WRITE" : "DRY-RUN (add --write to apply)") . "\n\n";

if ($rollbackArg) {
    if (! is_file($rollbackArg)) {
        fwrite(STDERR, "Backup file not found: {$rollbackArg}\n");
        exit(2);
    }
    $rows = json_decode((string) file_get_contents($rollbackArg), true);
    if (! is_array($rows)) {
        fwrite(STDERR, "Backup file is not a JSON array: {$rollbackArg}\n");
        exit(2);
    }
    $applied = 0;
    foreach ($rows as $r) {
        $component = Component::find($r['id']);
        if (! $component) {
            continue;
        }
        $component->specs = $r['specs'];
        $component->save();
        $applied++;
    }
    echo "ROLLBACK applied to {$applied} components. Verify with the read-only coverage script.\n";
    exit(0);
}

// Read the scrape once and key it exactly the way ScrapedCatalogSeeder does.
$scrapePath = __DIR__ . '/../database/scraped/power-supply.json';
$dump = json_decode((string) file_get_contents($scrapePath), true);
$bySlug = [];
foreach ((array) $dump as $row) {
    $name = trim((string) ($row['productName'] ?? ''));
    $url = (string) ($row['url'] ?? $name);
    if ($name === '') {
        continue;
    }
    $eff = trim((string) ($row['specs']['efficiencyRating'] ?? ''));
    if ($eff === '') {
        continue;
    }
    $slug = Str::slug($name) . '-' . substr(md5($url), 0, 6);
    if (! isset($bySlug[$slug])) {
        $bySlug[$slug] = $eff;
    }
}

$psuId = DB::table('categories')->where('slug', 'psu')->value('id');
if (! $psuId) {
    fwrite(STDERR, "No 'psu' category found.\n");
    exit(1);
}

$components = Component::where('category_id', $psuId)->where('active', true)->get();

$pending = [];   // rows that WILL change
$alreadyFilled = 0;
$noScrapeValue = 0;
$tiers = [];

foreach ($components as $c) {
    $specs = is_array($c->specs) ? $c->specs : [];
    $current = trim((string) ($specs['efficiency_rating'] ?? ''));
    if ($current !== '') {
        $alreadyFilled++;
        continue;
    }
    $eff = $bySlug[$c->slug] ?? '';
    if ($eff === '') {
        $noScrapeValue++;
        continue;
    }
    $pending[] = ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'value' => $eff, 'specs' => $specs];
    $tiers[$eff] = ($tiers[$eff] ?? 0) + 1;
}

printf("active PSUs scanned       : %d\n", $components->count());
printf("already have a rating     : %d\n", $alreadyFilled);
printf("no rating in the scrape   : %d (stays empty, correctly)\n", $noScrapeValue);
printf("would be filled           : %d\n", count($pending));
echo "\nProjected tier mix:\n";
arsort($tiers);
foreach ($tiers as $t => $n) {
    printf("  %-16s %d\n", $t, $n);
}

if (! $pending) {
    echo "\nNothing to do. Exiting.\n";
    exit(0);
}

if (! $write) {
    echo "\nDRY-RUN complete - no writes performed. Re-run with --write to apply.\n";
    exit(0);
}

// Backup BEFORE touching anything.
$backupDir = __DIR__ . '/backups';
if (! is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}
$stamp = gmdate('Ymd-His');
$backupFile = $backupDir . "/psu-efficiency-backfill-{$stamp}.json";

$backup = [];
foreach ($pending as $p) {
    $backup[] = ['id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'specs' => ($p['specs'] === [] ? null : $p['specs'])];
}
file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\nBackup written: {$backupFile}\n";

$applied = 0;
foreach ($pending as $p) {
    $component = Component::find($p['id']);
    if (! $component) {
        continue;
    }
    $specs = is_array($component->specs) ? $component->specs : [];
    $specs['efficiency_rating'] = $p['value'];
    $component->specs = $specs;
    $component->save();
    $applied++;
}

echo "WRITE complete: {$applied} PSUs updated.\n";
echo "Verify with: php scripts/genie-verify-psu-coverage-readonly.php\n";