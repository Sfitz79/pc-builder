<?php

/**
 * Put the drive capacity back into storage part names.
 *
 * WHY THIS EXISTS (2026-10-07)
 *
 * Found in production during live verification: the picker quoted a customer
 * "KIOXIA EXCERIA" with no capacity at all. The same defect class already fixed
 * for graphics cards (115 of 306 rows were "Asus PRIME OC" - a cooler with no
 * GPU), and measured the same way: of the in-stock storage rows, 74 carry no
 * capacity in the name.
 *
 * Unlike the GPU case, every one of those 74 already has a readable capacity in
 * specs.capacity, so the name can be completed from data already held.
 *
 * THE PLAUSIBILITY GATE IS THE POINT OF THIS SCRIPT
 *
 * specs.capacity is not trustworthy on its own. Measured, it contains values
 * that are not real drives:
 *
 *   "Intel D3-S4520"          -> 69632GB  (69.6TB - an enterprise line, not a
 *                                          product anyone can buy)
 *
 * Appending that would publish a sixty-nine-terabyte SSD to a customer, which
 * is precisely the hallucinated spec this whole pass exists to remove. So a
 * capacity is only written when it is one of the real drive sizes; anything
 * else is reported and left exactly as it is. The gate fails closed on the
 * data, not on the part.
 *
 * Real capacities, expressed in GB the way the catalogue expresses them:
 * 120/128/240/250/256/480/500/512/960/1000/1024/2000/2048/4000/4096/8000/8192
 * /16000/16384/30000/32768. Also accept the TB spellings 1/2/4/8/16/30/32.
 *
 * USAGE
 *
 *   php scripts/fix-storage-names.php           # dry run, prints every change
 *   php scripts/fix-storage-names.php --apply   # writes, after a backup
 */

use App\Models\Category;
use App\Models\Component;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);

/**
 * Does the name already say how big the drive is?
 */
function nameHasCapacity(string $name): bool
{
    return preg_match('/\d+(\.\d+)?\s?(TB|GB)\b/i', $name) === 1;
}

/**
 * The capacity in GB, or null when the stored value is not a real drive size.
 *
 * Returns null - never a guess - for anything unrecognised, so an implausible
 * value leaves the name untouched rather than being published.
 */
function plausibleCapacityGb($raw): ?int
{
    if ($raw === null) {
        return null;
    }

    $text = strtoupper(trim((string) $raw));

    if ($text === '') {
        return null;
    }

    if (str_contains($text, 'TB')) {
        $value = (float) str_replace('TB', '', $text);

        return in_array((int) $value, [1, 2, 3, 4, 8, 16, 30, 32], true) ? (int) $value * 1000 : null;
    }

    if (! preg_match('/^(\d+)/', $text, $m)) {
        return null;
    }

    $value = (int) $m[1];

    // The plausible sizes live in config so this script and the quoting gate
    // in AIRecommendationService::isQuotable() cannot drift apart.
    return in_array($value, config('builder.storage_capacity_gb', []), true) ? $value : null;
}

/**
 * "Corsair MP600 ELITE" + 2048 -> "Corsair MP600 ELITE 2TB"
 *
 * The catalogue stores the binary sizes (1024, 2048, 4096...), which are the
 * same capacities as 1/2/4TB but do not divide by 1000. Those are mapped
 * explicitly. Everything else is written in GB, which is how it is sold.
 */
function capacityLabel(int $gb): string
{
    $asTb = [1024 => '1TB', 2048 => '2TB', 4096 => '4TB', 8192 => '8TB',
        16384 => '16TB', 32768 => '32TB', 30000 => '30TB', 16000 => '16TB', 8000 => '8TB'];

    return $asTb[$gb] ?? $gb . 'GB';
}

$category = Category::where('slug', 'storage')->first();

if ($category === null) {
    fwrite(STDERR, "No storage category found.\n");
    exit(1);
}

$drives = Component::where('category_id', $category->id)->get();

$targets = $drives->filter(fn (Component $c) => ! nameHasCapacity((string) $c->name));

printf("Storage rows            : %d%s", $drives->count(), PHP_EOL);
printf("names already sized     : %d%s", $drives->count() - $targets->count(), PHP_EOL);
printf("names needing a capacity: %d%s%s", $targets->count(), PHP_EOL, PHP_EOL);
printf("MODE: %s%s%s", $apply ? 'APPLY' : 'DRY RUN', PHP_EOL, PHP_EOL);

$taken = $drives
    ->reject(fn (Component $c) => $targets->contains('id', $c->id))
    ->map(fn (Component $c) => mb_strtolower(trim((string) $c->name)))
    ->flip();

$planned = [];
$skipped = [];

foreach ($targets as $drive) {
    $gb = plausibleCapacityGb($drive->specs['capacity'] ?? null);

    if ($gb === null) {
        $skipped[] = [
            'id' => $drive->id,
            'name' => $drive->name,
            'why' => sprintf('implausible or missing capacity: %s', json_encode($drive->specs['capacity'] ?? null)),
        ];
        continue;
    }

    $newName = trim(preg_replace('/\s+/', ' ', $drive->name . ' ' . capacityLabel($gb)) ?? '');

    $key = mb_strtolower($newName);

    if ($taken->has($key)) {
        $skipped[] = ['id' => $drive->id, 'name' => $drive->name, 'why' => "collides with an existing row: {$newName}"];
        continue;
    }

    $taken->put($key, $drive->id);
    $planned[] = ['drive' => $drive, 'name' => $newName, 'gb' => $gb];
}

printf("would rename : %d%s", count($planned), PHP_EOL);
printf("would skip   : %d%s%s", count($skipped), PHP_EOL, PHP_EOL);

echo str_repeat('-', 74), PHP_EOL;
foreach (array_slice($planned, 0, 15) as $row) {
    printf("%-42s -> %s%s", $row['drive']->name, $row['name'], PHP_EOL);
}
if (count($planned) > 15) {
    printf('... and %d more%s', count($planned) - 15, PHP_EOL);
}

if ($skipped !== []) {
    echo str_repeat('-', 74), PHP_EOL;
    echo 'SKIPPED (left exactly as they are):', PHP_EOL;
    foreach ($skipped as $row) {
        printf('  %-40s %s%s', $row['name'], $row['why'], PHP_EOL);
    }
}
echo str_repeat('-', 74), PHP_EOL;

if ($planned === []) {
    echo PHP_EOL, 'NOTHING TO DO. No names were written.', PHP_EOL;
    exit(0);
}

if (! $apply) {
    echo PHP_EOL, 'Dry run. Re-run with --apply to write.', PHP_EOL;
    exit(0);
}

$backupPath = __DIR__ . '/../database/backups/storage-names-before.json';

if (! is_dir(dirname($backupPath))) {
    mkdir(dirname($backupPath), 0777, true);
}

file_put_contents($backupPath, json_encode([
    'captured_at' => now()->toIso8601String(),
    'renamed' => $planned,
    'skipped' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf('Backup written: %s%s%s', $backupPath, PHP_EOL, PHP_EOL);

$written = 0;

DB::transaction(function () use ($planned, &$written) {
    foreach ($planned as $row) {
        /** @var Component $drive */
        $drive = $row['drive'];
        $drive->name = $row['name'];
        $drive->save();
        $written++;
    }
});

printf('Renamed : %d%s', $written, PHP_EOL);

$after = Component::where('category_id', $category->id)->get();
$stillUnsized = $after->filter(fn (Component $c) => ! nameHasCapacity((string) $c->name));

printf('Verifying: %d of %d storage names state a capacity (was %d of %d)%s',
    $after->count() - $stillUnsized->count(), $after->count(),
    $drives->count() - $targets->count(), $drives->count(), PHP_EOL);

if ($stillUnsized->isNotEmpty()) {
    echo PHP_EOL, 'Still unsized after the run:', PHP_EOL;
    foreach ($stillUnsized as $row) {
        printf('  %-44s specs=%s%s', $row->name, json_encode($row->specs['capacity'] ?? null), PHP_EOL);
    }
}
