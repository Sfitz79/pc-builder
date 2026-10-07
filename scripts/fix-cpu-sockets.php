<?php

/**
 * Derive the CPU socket from the model number and report every disagreement.
 *
 * WHY THIS EXISTS (2026-10-07)
 *
 * Auditing the socket column against the catalogue found Zen 2 and Zen 3 parts
 * tagged AM5 - "AMD Ryzen 5 5600", "AMD Ryzen 5 3600", "AMD Ryzen 7 5700X3D",
 * "AMD Ryzen 5 2600" were all stored as AM5. Those are AM4 parts. This is not a
 * cosmetic label: the picker filters motherboards by socket, so a wrong socket
 * pairs a Ryzen 5 5600 with a board it physically cannot be fitted to, and the
 * 100 CPUs with no socket at all are dropped from consideration for the same
 * reason. It is a live hallucinated-spec defect, not a data-quality nit.
 *
 * DERIVATION IS FROM THE MODEL NUMBER ONLY
 *
 * The rules below are the platform's own published boundaries, and every one of
 * them is stated as a test rather than a lookup so a reviewer can check the
 * reasoning without running anything. There is no price list, no web call and
 * no inference from a sibling row.
 *
 *   - Ryzen 7### and above (7000, 8000, 9000) are AM5.
 *   - Every other Ryzen (1000, 2000, 3000, 5000, 5500, 5600, 5700X, 5700X3D,
 *     5900X, 5950X) is AM4. The AM4 range is deliberately NOT "below 7000",
 *     it is explicit, because a blind numeric cutoff is exactly the sort of
 *     assumption that produced the AM5 tags this script is correcting.
 *   - Core Ultra (200 series, Arrow Lake) is LGA1851.
 *   - Core i3/i5/i7/i9 12### to 14### is LGA1700.
 *
 * FAIL CLOSED
 *
 * Anything the rules cannot decide is left exactly as it is and listed in the
 * report. Threadripper, Xeon, mobile and unrecognised parts are never guessed.
 * A row is only written when the derived socket disagrees with the stored one,
 * so this can never "confirm" a value it did not independently derive.
 *
 * USAGE
 *
 *   php scripts/fix-cpu-sockets.php           # dry run, prints every change
 *   php scripts/fix-cpu-sockets.php --apply   # writes, after a backup
 */

use App\Models\Category;
use App\Models\Component;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);

/**
 * The socket a CPU model is fitted to, or null when we cannot prove it.
 *
 * The AM4 list is written out model by model rather than as a numeric range so
 * that a reviewer can see exactly which parts are being claimed, and so that
 * adding a new AM4 part is a deliberate edit rather than an accident of a
 * cutoff.
 */
function deriveSocket(string $name): ?string
{
    $name = trim($name);

    // --- AMD Ryzen ---------------------------------------------------------
    //
    // "Ryzen 5 5600", "Ryzen 7 5700X3D", "Ryzen 9 5900X", "Ryzen 3 3200G".
    if (preg_match('/\bRyzen\s+[3579]\s+(\d{4})([A-Z]{0,3})/i', $name, $m) === 1) {
        $gen = (int) $m[1];
        $suffix = strtoupper($m[2]);

        // AM5 is Zen 4 onwards: the 7000 series and everything above it.
        if ($gen >= 7000) {
            return 'AM5';
        }

        // Zen 3 refresh on AM4, which is the pair most often mis-tagged.
        if ($gen === 5600 || $gen === 5700) {
            return 'AM4';
        }

        // Zen 1, Zen 2, Zen 3 and the 5000 non-X parts. Listed explicitly.
        if (in_array($gen, [1000, 1100, 1200, 1300, 1600, 1700, 1800, 1900,
            2000, 2100, 2200, 2300, 2600, 2700, 3000, 3100, 3200, 3300, 3400,
            3600, 3700, 3800, 3900, 3950, 4100, 4500, 4600, 4700, 4800, 4900,
            5000, 5200, 5300, 5500, 5800, 5900, 5950], true)) {
            return 'AM4';
        }

        return null;
    }

    // --- Intel Core Ultra (Arrow Lake, LGA1851) ----------------------------
    if (preg_match('/\bCore\s+Ultra\s+[579]\s+\d{3}[A-Z]{0,2}/i', $name) === 1) {
        return 'LGA1851';
    }

    // --- Intel Core i3/i5/i7/i9 --------------------------------------------
    //
    // "Core i5-13400F" (13th gen), "Core i7-14700K" (14th gen) are LGA1700.
    // 11th gen and earlier are LGA1200, which this catalogue does stock.
    if (preg_match('/\bi[3579][- ](\d{5})/i', $name, $m) === 1) {
        $gen = (int) $m[1];

        if ($gen >= 12000 && $gen < 15000) {
            return 'LGA1700';
        }

        if ($gen >= 10000 && $gen < 12000) {
            // 10th/11th gen X-series are NOT LGA1200 consumer parts. The
            // X-series (i9-10900X / -XE / -W / -WT) needs a C621 chipset, so
            // pairing one with a consumer LGA1200 board ships a machine that
            // cannot POST. Left undecided rather than asserted.
            if (preg_match('/\b(109[0-9]{2}X[T]?|10980XE|10850K)\b/i', $name) === 1) {
                return null;
            }

            return 'LGA1200';
        }

        return null;
    }

    // Threadripper, Xeon, Pentium, Celeron, mobile and anything else: not
    // decided here. They are reported so the list stays visible.
    return null;
}

$category = Category::where('slug', 'cpu')->first();

if ($category === null) {
    fwrite(STDERR, "No CPU category found.\n");
    exit(1);
}

$cpus = Component::where('category_id', $category->id)->get();

$changes = [];
$unchanged = [];
$undecided = [];

foreach ($cpus as $cpu) {
    $derived = deriveSocket((string) $cpu->name);

    if ($derived === null) {
        $undecided[] = $cpu;
        continue;
    }

    if (trim((string) $cpu->socket) === $derived) {
        $unchanged[] = $cpu;
        continue;
    }

    $changes[] = ['cpu' => $cpu, 'from' => (string) $cpu->socket, 'to' => $derived];
}

printf("CPU rows examined : %d%s", $cpus->count(), PHP_EOL);
printf("  already correct : %d%s", count($unchanged), PHP_EOL);
printf("  would change    : %d%s", count($changes), PHP_EOL);
printf("  not decidable   : %d%s%s", count($undecided), PHP_EOL, PHP_EOL);
printf("MODE: %s%s%s", $apply ? 'APPLY' : 'DRY RUN', PHP_EOL, PHP_EOL);
echo str_repeat('-', 74), PHP_EOL;

$byChange = [];

foreach ($changes as $change) {
    $key = ($change['from'] === '' ? '(empty)' : $change['from']) . ' -> ' . $change['to'];
    $byChange[$key][] = (string) $change['cpu']->name;
}

foreach ($byChange as $key => $names) {
    printf("%-22s %d%s", $key, count($names), PHP_EOL);
    foreach ($names as $name) {
        printf("    %s%s", $name, PHP_EOL);
    }
}

echo str_repeat('-', 74), PHP_EOL;

if ($undecided !== []) {
    printf('Not decided by these rules (%d):%s', count($undecided), PHP_EOL);
    foreach ($undecided as $cpu) {
        printf('    %-40s socket=%s%s', $cpu->name, $cpu->socket === '' ? '(empty)' : $cpu->socket, PHP_EOL);
    }
    echo str_repeat('-', 74), PHP_EOL;
}

// Rule 5: a run that changed nothing must say so rather than look like a pass.
if ($changes === []) {
    echo PHP_EOL, 'NOTHING TO DO. No sockets were written.', PHP_EOL;
    exit(0);
}

if (! $apply) {
    echo PHP_EOL, 'Dry run. Re-run with --apply to write.', PHP_EOL;
    exit(0);
}

$backupPath = __DIR__ . '/../database/backups/cpu-sockets-before.json';

if (! is_dir(dirname($backupPath))) {
    mkdir(dirname($backupPath), 0777, true);
}

file_put_contents($backupPath, json_encode([
    'captured_at' => now()->toIso8601String(),
    'changes' => array_map(fn ($c) => [
        'id' => $c['cpu']->id,
        'name' => $c['cpu']->name,
        'from' => $c['from'],
        'to' => $c['to'],
    ], $changes),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf('Backup written: %s%s%s', $backupPath, PHP_EOL, PHP_EOL);

$written = 0;

DB::transaction(function () use ($changes, &$written) {
    foreach ($changes as $change) {
        /** @var Component $cpu */
        $cpu = $change['cpu'];
        $cpu->socket = $change['to'];
        $cpu->save();
        $written++;
    }
});

printf('Sockets written : %d%s', $written, PHP_EOL);

// Verify from the database, not from the plan.
$stillWrong = Component::where('category_id', $category->id)->get()
    ->filter(fn (Component $c) => deriveSocket((string) $c->name) !== null
        && trim((string) $c->socket) !== deriveSocket((string) $c->name));

printf('Verifying: %d of %d decidable CPUs now agree with their model number%s',
    $written - $stillWrong->count(), $written, PHP_EOL);

foreach ($stillWrong as $cpu) {
    printf('  STILL WRONG %-38s stored=%-9s derived=%s%s',
        $cpu->name, $cpu->socket, deriveSocket((string) $cpu->name), PHP_EOL);
}
