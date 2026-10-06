<?php

/**
 * scripts/dimension-provenance.php
 *
 * THE DEFECT THIS MEASURES
 * ------------------------
 * PartDimensions::resolve() falls back to HARDCODED numbers when a dimension is
 * missing from the catalogue:
 *
 *     gpuDims()    $specs['length'] ?? 300
 *     caseDims()   $specs['height'] ?? 460,  width ?? 230,  depth ?? 460
 *                  max_gpu_length ?? max(320, depth - 80)
 *                  max_cpu_cooler_height ?? 170
 *     coolerDims() $specs['height'] ?? 160
 *
 * None of those announce themselves. The viewport therefore draws a confident,
 * to-scale-looking machine built substantially from defaults, and
 * verify-3d-assembly.php reports "every internal sits inside the case envelope" -
 * a verdict produced by comparing invented numbers against other invented numbers.
 * That gate cannot fail on missing data, so on this data it is not evidence.
 *
 * NOT the same as the customer-facing fit check. FitVerification reads the RAW
 * parts array ($part['length'], $part['max_gpu_length']) and reports `unverified`
 * when a value is absent, which is correct and fail-closed. Fabricated values never
 * reach that promise. They reach the 3D VIEWPORT, which is where a customer looks
 * and concludes the build is right.
 *
 * TWO KINDS OF FALLBACK, AND THE REPORT KEEPS THEM APART
 * ------------------------------------------------------
 *   FABRICATED - no basis at all. 300mm for a GPU, 460x230x460 for a case, 170mm
 *                cooler clearance. A guess presented as a measurement.
 *   STANDARD   - published and real. Mini-ITX is 170x170mm, an ATX PSU is
 *                140x86x150mm, SFX is 100x63.5x125mm. motherboardDims() and
 *                psuDims() fall back to these, which is legitimate: the number is
 *                true for the form factor even when the specific row is silent.
 *
 * Counting the standards as fabrications overstates the problem, so they are
 * counted separately and reported both ways.
 *
 * USAGE
 *     php scripts/dimension-provenance.php              local SQLite
 *     php scripts/dimension-provenance.php --production PRODUCTION Neon
 */

$root = dirname(__DIR__);
$production = in_array('--production', $argv, true);

require $root . '/vendor/autoload.php';

/** Fetch [slug, name, specs] for all active components. */
$fetch = function (): array {
    global $root, $production;

    if (! $production) {
        $app = require $root . '/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $rows = Illuminate\Support\Facades\DB::table('components')
            ->join('categories', 'components.category_id', '=', 'categories.id')
            ->where('components.active', true)
            ->select('categories.slug', 'components.name', 'components.specs')
            ->get();
    } else {
        // Raw PDO, matching prod-gate-verify.php. The app's 'mysql' connection entry
        // is pointed at a Postgres host and fails with "could not find driver";
        // going through PDO avoids the mislabelled connection entirely.
        $env = [];
        foreach (file($root . '/.env.production.neon', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim(trim($v), "\"'");
        }
        $host = $env['DB_HOST'] ?? '';
        $user = $env['DB_USERNAME'] ?? '';
        if ($host === '' || $user === '') {
            fwrite(STDERR, "No Neon credentials in .env.production.neon\n");
            exit(2);
        }
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                $host, $env['DB_PORT'] ?? '5432', $env['DB_DATABASE'] ?? '', $env['DB_SSLMODE'] ?? 'require'),
            $user,
            $env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20]
        );
        // `active = true` is a BOOLEAN comparison. This is the Rule-7 trap: writing
        // `= 1` works on SQLite and throws on Postgres with
        // "operator does not exist: boolean = integer".
        $rows = $pdo->query(
            "SELECT c.slug AS slug, comp.name AS name, comp.specs AS specs
             FROM components comp JOIN categories c ON c.id = comp.category_id
             WHERE comp.active = true"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $out = [];
    foreach ($rows as $r) {
        // Both sources hand back different shapes: the Laravel collection gives
        // stdClass, PDO gives arrays. Normalise here rather than at every use site -
        // reading $r['slug'] off an stdClass is "Cannot use object of type stdClass
        // as array", which is what the first --production run failed with.
        $get = static function (object|array $row, string $key): mixed {
            return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
        };

        $out[] = [
            'slug' => (string) $get($r, 'slug'),
            'name' => (string) $get($r, 'name'),
            'specs' => json_decode((string) ($get($r, 'specs') ?? ''), true) ?: [],
        ];
    }

    return $out;
};

// key => [catalogue keys tried, fallback label, kind]
// kind: 'fabricated' = invented, 'standard' = published and true.
$READS = [
    'case' => [
        ['height', 'width', 'depth'], '460 x 230 x 460 (generic mid-tower)', 'fabricated',
        'max_gpu_length', 'max(320, depth - 80)', 'fabricated',
        'max_cpu_cooler_height', '170', 'fabricated',
        ['rad_top', 'rad_front'], '360', 'fabricated',
        ['form_factor', 'supported_form_factors[0]'], "'mid-tower'", 'fabricated',
    ],
    'gpu' => [
        ['length'], '300', 'fabricated',
        ['height'], '120', 'fabricated',
        ['thickness_slots'], '2.75 (56mm)', 'fabricated',
    ],
    'cooler' => [
        ['height'], '160', 'fabricated',
        ['radiator_length', 'rad_length'], '360', 'fabricated',
    ],
    'motherboard' => [
        ['form_factor'], 'ATX 305x244 (published standard)', 'standard',
    ],
    'psu' => [
        ['form', 'form_factor'], 'ATX 140x86x150 (published standard)', 'standard',
    ],
    'ram' => [['height'], '32', 'fabricated'],
    'cpu' => [['height', 'cpu_height'], '90', 'fabricated'],
    'storage' => [['width'], '80', 'fabricated', 'depth', '22', 'fabricated'],
];

try {
    $items = $fetch();
} catch (Throwable $e) {
    fwrite(STDERR, 'QUERY FAILED: ' . $e->getMessage() . "\n");
    exit(3);
}

$src = $production ? 'PRODUCTION Neon' : 'local SQLite';
echo "\n=== dimension provenance ({$src}) ===\n";
echo 'active components: ' . count($items) . "\n";

$byCat = [];
foreach ($items as $it) {
    $byCat[$it['slug']][] = $it;
}

$totSourced = $totFab = $totStd = 0;
$fabRows = [];
$stdRows = [];

foreach ($READS as $cat => $specs) {
    $rows = $byCat[$cat] ?? [];
    if (! $rows) {
        continue;
    }
    $n = count($rows);

    echo "\n  {$cat} ({$n} active)\n";
    // Walk the flat [keys..., fallback, kind] tuples two at a time.
    for ($i = 0; $i < count($specs); $i += 3) {
        $keys = $specs[$i];
        $fallback = $specs[$i + 1];
        $kind = $specs[$i + 2];
        $keys = is_array($keys) ? $keys : [$keys];

        $have = 0;
        foreach ($rows as $r) {
            foreach ($keys as $k) {
                $v = $r['specs'][$k] ?? null;
                if ($v !== null && $v !== '' && $v !== []) {
                    $have++;
                    break;
                }
            }
        }
        $pct = $have / $n * 100;
        $label = implode(' | ', $keys);

        if ($kind === 'fabricated') {
            $totFab += $n;
            $fabRows[] = [$cat, $label, $have, $n, $pct, $fallback];
        } else {
            $totStd += $n;
            $stdRows[] = [$cat, $label, $have, $n, $pct, $fallback];
        }
        $totSourced += $have;

        printf("    %-26s %5d/%-5d %5.1f%%  [%s] fallback: %s\n",
            $label, $have, $n, $pct, $kind, $fallback);
    }
}

$totKeys = $totFab + $totStd;
$fabSourced = 0;
foreach ($fabRows as $r) { $fabSourced += $r[2]; }
$stdSourced = 0;
foreach ($stdRows as $r) { $stdSourced += $r[2]; }

echo "\n  ---------------------------------------------------------------\n";
printf("  catalogue-sourced            : %5d / %5d  (%5.1f%%)\n", $totSourced, $totKeys, $totKeys ? $totSourced / $totKeys * 100 : 0);
printf("  FABRICATED fallback keys     : %5d / %5d  (%5.1f%% sourced)\n", $fabSourced, $totFab, $totFab ? $fabSourced / $totFab * 100 : 0);
printf("  STANDARD  fallback keys      : %5d / %5d  (%5.1f%% sourced)\n\n", $stdSourced, $totStd, $totStd ? $stdSourced / $totStd * 100 : 0);

$fabPct = $totFab ? $fabSourced / $totFab * 100 : 100;
if ($fabPct < 5) {
    echo "  VERDICT: essentially every physical dimension the viewport draws is\n";
    echo "           FABRICATED. A GPU with no recorded length is drawn 300mm long,\n";
    echo "           a case with no recorded clearance gets max(320, depth-80), and\n";
    echo "           those two guesses are then compared against each other by\n";
    echo "           verify-3d-assembly.php, which therefore cannot fail. Treat every\n";
    echo "           rendered machine as ILLUSTRATIVE. The customer-facing fit gate\n";
    echo "           (FitVerification) is unaffected and still fails closed.\n\n";
} elseif ($fabPct < 50) {
    echo "  VERDICT: dimensions are PARTLY fabricated. Any clearance claim must be\n";
    echo "           traced to a key listed above as sourced, not to the render.\n\n";
} else {
    echo "  VERDICT: physical dimensions are largely sourced; assembly conclusions\n";
    echo "           drawn from the viewport are meaningful.\n\n";
}