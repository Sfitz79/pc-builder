<?php

/**
 * Recover the identity of graphics cards whose names lost their model.
 *
 * WHY THIS EXISTS (2026-10-07)
 *
 * An earlier report claimed GPU name ambiguity had fallen from 53 to 11. That
 * claim was WRONG and is retracted: it measured name COLLISIONS, not whether a
 * name identifies a product at all. Measured properly, against the field the
 * customer actually reads, 115 of 306 GPU rows carried no model-family token
 * and were quotable as things like "Asus PRIME OC" or "Gigabyte GAMING OC".
 *
 * That is a live brand-integrity defect: we cannot quote a customer a graphics
 * card by its cooler model without saying what GPU it is.
 *
 * THE DATA IS NOT LOST. Every one of these rows still carries:
 *
 *   - specs.chipset  -> the full model, e.g. "GeForce RTX 5070"
 *   - source_url     -> a PCPartPicker product slug whose tail, after the
 *                       literal "video-card", is the manufacturer's part number
 *
 * so the identity is recoverable from data already in the database. No web
 * call, no guessing.
 *
 * WHAT IT WRITES
 *
 *   name     = "<existing brand words> <chipset> <part number>"
 *   chipset  = specs.chipset          (the column was empty on all 115)
 *
 * Name-only and column-only writes. It NEVER deletes, deactivates or re-prices
 * anything, so the FKs from build_components / component_attributes /
 * component_images / inventory / price_history / benchmarks are untouched.
 *
 * FAIL CLOSED. A row is skipped and reported if any of these hold:
 *
 *   - no chipset in specs
 *   - no source_url, or no "video-card" segment to read the part number from
 *   - the reconstructed name already belongs to a DIFFERENT component
 *
 * A skipped row keeps its current name. It is never made unique by inventing a
 * suffix, and nothing is silently truncated to force a fit.
 *
 * USAGE
 *
 *   php scripts/fix-gpu-identity.php              # dry run, prints the report
 *   php scripts/fix-gpu-identity.php --apply      # writes, after a backup
 */

use App\Models\Category;
use App\Models\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);

/**
 * A name is quotable when it names a PRODUCT, not just a board partner's
 * cooler. Every current-generation NVIDIA/AMD family token we sell.
 */
function nameIdentifiesGpu(string $name): bool
{
    return preg_match('/\b(RTX\s?\d{4}|RX\s?\d{4}|GTX\s?\d{4}|GT\s?\d{3}|Arc\s?[AB]\d{3}|Quadro\s?R?T?\d{3,4}|Radeon\s?Pro\s?W[A-Z]{0,2}\s?\d{4}|Radeon\s?Pro\s?W[A-Z]{0,2})\b/i', $name) === 1;
}

/**
 * A different GPU model explicitly named in $mpn than the one $chipset names.
 *
 * Deliberately conservative. Vendor SKUs encode far more than the model - EVGA
 * uses "11G-P4-6390-KR", where 6390 is a launch-week code, not a model - so the
 * mere PRESENCE of an unrelated 4-digit number in a SKU proves nothing and must
 * not block a correct name. Only an explicit, differently-numbered model of the
 * same family counts as a contradiction.
 *
 * Returns null when there is no contradiction to report.
 */
function conflictingModel(string $chipset, string $mpn): ?string
{
    if (preg_match('/\b(RTX|RX|GTX|GT)\s?-?\s?(\d{4})\b/i', $chipset, $c) !== 1) {
        return null;
    }

    $family = strtoupper($c[1]);
    $model = $c[2];

    if (preg_match_all('/\b(RTX|RX|GTX|GT)\s?-?\s?(\d{4})\b/i', $mpn, $all, PREG_SET_ORDER) === 0) {
        return null;
    }

    foreach ($all as $hit) {
        if (strtoupper($hit[1]) === $family && $hit[2] !== $model) {
            return "{$family} {$hit[2]}";
        }
    }

    return null;
}

/**
 * Turn "asus-prime-oc-geforce-rtx-5070-12-gb-video-card-prime-rtx5070-o12g"
 * into "PRIME RTX5070-O12G".
 *
 * The part number is everything after the last "video-card" marker. Anything
 * after that is the vendor's own SKU, which is the only thing that separates
 * an ASUS PRIME from a ROG Strix of the same GPU.
 */
function partNumberFromUrl(?string $url): ?string
{
    if ($url === null || trim($url) === '') {
        return null;
    }

    // Everything after the last path/query segment, de-slugged.
    if (preg_match('#video-card[-/]?(?<mpn>[^/?\#]+)#i', $url, $m) !== 1) {
        return null;
    }

    $mpn = trim(Str::of($m['mpn'])->replace('-', ' ')->squish()->toString());

    // A part number is alphanumeric and has to have some substance. Reject the
    // empty case and anything that is still a slug.
    if ($mpn === '' || strlen($mpn) < 4 || ! preg_match('/\d/', $mpn)) {
        return null;
    }

    return $mpn;
}

/**
 * "Gigabyte GAMING OC" + "Radeon RX 9060 XT" + "GV-R9060XTGAMING-OC-16GD"
 * -> "Gigabyte GAMING OC Radeon RX 9060 XT GV-R9060XTGAMING-OC-16GD"
 */
function buildName(string $current, string $chipset, string $mpn): string
{
    $base = trim(preg_replace('/\s+/', ' ', $current) ?? $current);
    $chipset = trim(preg_replace('/\s+/', ' ', $chipset) ?? $chipset);
    $mpn = trim($mpn);

    // Drop any chipset/part number already present so re-running is idempotent.
    if ($chipset !== '' && Str::contains(Str::lower($base), Str::lower($chipset))) {
        $base = trim(str_ireplace($chipset, '', $base));
    }

    return trim(preg_replace('/\s+/', ' ', $base . ' ' . $chipset . ' ' . $mpn) ?? '');
}

$category = Category::where('slug', 'gpu')->first();

if ($category === null) {
    fwrite(STDERR, "No GPU category found.\n");
    exit(1);
}

$gpus = Component::where('category_id', $category->id)->get();

$targets = $gpus->filter(fn (Component $c) => ! nameIdentifiesGpu((string) $c->name));

printf("GPU rows                 : %d%s", $gpus->count(), PHP_EOL);
printf("names that identify a GPU: %d%s", $gpus->count() - $targets->count(), PHP_EOL);
printf("names needing recovery   : %d%s%s", $targets->count(), PHP_EOL, PHP_EOL);
printf("MODE: %s%s%s", $apply ? 'APPLY' : 'DRY RUN', PHP_EOL, PHP_EOL);

// The exact set of names already in use, so a collision is detected against
// real rows rather than only against rows processed earlier in this run.
$takenNames = $gpus
    ->reject(fn (Component $c) => $targets->contains('id', $c->id))
    ->map(fn (Component $c) => mb_strtolower(trim((string) $c->name)))
    ->flip();

$planned = [];
$skipped = [];

foreach ($targets as $component) {
    $chipset = trim((string) ($component->specs['chipset'] ?? ''));

    if ($chipset === '') {
        $skipped[] = ['id' => $component->id, 'name' => $component->name, 'why' => 'no specs.chipset'];
        continue;
    }

    $mpn = partNumberFromUrl($component->source_url);

    if ($mpn === null) {
        $skipped[] = ['id' => $component->id, 'name' => $component->name, 'why' => 'no part number in source_url'];
        continue;
    }

    $newName = buildName((string) $component->name, $chipset, $mpn);

    if (! nameIdentifiesGpu($newName)) {
        $skipped[] = ['id' => $component->id, 'name' => $component->name, 'why' => 'reconstruction still unidentifiable'];
        continue;
    }

    // Never write a name that contradicts itself. If the vendor SKU names a
    // different model of the same family than specs.chipset does, the row is
    // left alone for a human: one of the two is wrong and we cannot tell
    // which, so publishing either would be a hallucinated spec.
    $conflict = conflictingModel($chipset, $mpn);

    if ($conflict !== null) {
        $skipped[] = [
            'id' => $component->id,
            'name' => $component->name,
            'why' => "CONFLICT: specs.chipset says {$chipset} but the SKU names {$conflict} ({$mpn})",
        ];
        continue;
    }

    $key = mb_strtolower($newName);

    if ($takenNames->has($key)) {
        $skipped[] = ['id' => $component->id, 'name' => $component->name, 'why' => "collides with an existing row: {$newName}"];
        continue;
    }

    $takenNames->put($key, $component->id);
    $planned[] = ['component' => $component, 'name' => $newName, 'chipset' => $chipset];
}

printf("would rename : %d%s", count($planned), PHP_EOL);
printf("would skip   : %d%s%s", count($skipped), PHP_EOL, PHP_EOL);

echo str_repeat('-', 78), PHP_EOL;
foreach (array_slice($planned, 0, 20) as $row) {
    printf("%-34s -> %s%s", $row['component']->name, $row['name'], PHP_EOL);
}
if (count($planned) > 20) {
    printf('... and %d more%s', count($planned) - 20, PHP_EOL);
}

if ($skipped !== []) {
    echo str_repeat('-', 78), PHP_EOL;
    echo 'SKIPPED (left exactly as they are):', PHP_EOL;
    foreach ($skipped as $row) {
        printf('  %-30s %s%s', $row['name'], $row['why'], PHP_EOL);
    }
}
echo str_repeat('-', 78), PHP_EOL;

// Rule 5: a run that quietly changes nothing must say so loudly rather than
// looking like a success.
if ($planned === []) {
    echo PHP_EOL, 'NOTHING TO DO. No names were written.', PHP_EOL;
    exit(0);
}

if (! $apply) {
    echo PHP_EOL, 'Dry run. Re-run with --apply to write.', PHP_EOL;
    exit(0);
}

$backupPath = __DIR__ . '/../database/backups/gpu-identity-before.json';

if (! is_dir(dirname($backupPath))) {
    mkdir(dirname($backupPath), 0777, true);
}

file_put_contents($backupPath, json_encode([
    'captured_at' => now()->toIso8601String(),
    'rows' => $planned,
    'skipped' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf('Backup written: %s%s%s', $backupPath, PHP_EOL, PHP_EOL);

$renamed = 0;
$chipsetSet = 0;

DB::transaction(function () use ($planned, &$renamed, &$chipsetSet) {
    foreach ($planned as $row) {
        /** @var Component $component */
        $component = $row['component'];

        $component->name = $row['name'];

        // The chipset column is what the storefront and gpuPerformanceTier()
        // read. It was empty on every one of these rows.
        if (trim((string) $component->chipset) === '') {
            $component->chipset = $row['chipset'];
            $chipsetSet++;
        }

        $component->save();
        $renamed++;
    }
});

printf('Renamed        : %d%s', $renamed, PHP_EOL);
printf('chipset set    : %d%s', $chipsetSet, PHP_EOL);

// Verify against the same test the report was built on, from the database
// rather than from the plan.
$after = Component::where('category_id', $category->id)->get();
$stillBad = $after->filter(fn (Component $c) => ! nameIdentifiesGpu((string) $c->name));

printf('Verifying: %d of %d GPU names identify a GPU (was %d of %d)%s',
    $after->count() - $stillBad->count(), $after->count(),
    $gpus->count() - $targets->count(), $gpus->count(), PHP_EOL);

if ($stillBad->isNotEmpty()) {
    echo PHP_EOL, 'Still unidentifiable after the run:', PHP_EOL;
    foreach ($stillBad as $row) {
        printf('  %-6d %s%s', $row->id, $row->name, PHP_EOL);
    }
}
