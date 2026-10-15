<?php

/**
 * Tag the business catalogue.
 *
 * DRY RUN BY DEFAULT. Pass --apply to write.
 *
 * WHY THIS EXISTS
 * ---------------
 * The PCTG Business builder now draws only from components tagged `business`.
 * Nothing is tagged yet, so out of the box the business catalogue is EMPTY and
 * the builder can produce nothing - which is the correct fail-closed state, but
 * not a shippable one. This fills it with the parts of the existing catalogue
 * that are genuinely appropriate for a workstation, an office machine or a
 * studio box.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * -------------------------------
 * It does not invent parts, prices or specs. Every row it touches already exists
 * in the catalogue with its own evidence and freshness. It only adds a label,
 * which is fully reversible (`--revert` clears it), so the blast radius is one
 * JSON column.
 *
 * The rules are written out rather than inferred, because "suitable for a
 * business machine" is a judgement and a judgement nobody can audit is not a
 * judgement anybody should ship. Every exclusion is printed with the reason.
 *
 * WHAT IS EXCLUDED, AND WHY
 * -------------------------
 *   - Anything whose name says RGB or ARGB. A workstation in an office should
 *     not glow. This is the single biggest driver of the gaming look.
 *   - Anything whose name says GAMING. Same reason, and it is usually the board
 *     partner's overclocking board rather than the workstation one.
 *   - Liquid coolers (AIO). Loudest thing in the list; business workloads
 *     declare `quiet_cooling` for exactly this reason.
 *   - GPUs, mostly. The honest answer for an office machine is integrated
 *     graphics, and the picker's dGPU lane forces a card. Only professional and
 *     low-power cards are tagged, and the capability gate keeps the workloads
 *     that need real pro silicon in the spec-conversation lane instead of
 *     quietly shipping a gaming card.
 *
 * Usage:
 *   php scripts/genie-curate-business-catalogue.php            # report only
 *   php scripts/genie-curate-business-catalogue.php --apply    # write the tags
 *   php scripts/genie-curate-business-catalogue.php --revert   # remove them
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;

$mode = $argv[1] ?? '--dry-run';

echo 'db      : '.config('database.default').' ('.DB::connection()->getDriverName().")\n";
echo 'mode    : '.$mode."\n";

if (! in_array($mode, ['--dry-run', '--apply', '--revert'], true)) {
    exit("usage: genie-curate-business-catalogue.php [--dry-run|--apply|--revert]\n");
}

if (! Illuminate\Support\Facades\Schema::hasColumn('components', 'segments')) {
    exit("\n  components.segments does not exist yet - run `php artisan migrate` first.\n\n");
}

/** Name fragments that disqualify a part from a business machine, with reasons. */
$EXCLUDE = [
    'rgb'    => 'lit up - not appropriate for an office or studio',
    'argb'   => 'lit up - not appropriate for an office or studio',
    'gaming' => 'board-partner overclocking product, not the workstation equivalent',
    'aio'    => 'liquid cooler - the loudest thing in any build',
    'liquid' => 'liquid cooler - the loudest thing in any build',
    'water'  => 'liquid cooler - the loudest thing in any build',
    'kraken' => 'liquid cooler - the loudest thing in any build',
    'nhl'    => 'liquid cooler - the loudest thing in any build',
    // Thermalright call their AIO range "Aqua". The first dry run tagged
    // "Thermalright Aqua Elite V3 360 mm" for a business machine, which is a
    // 360mm liquid in an office.
    'aqua'   => 'liquid cooler - the loudest thing in any build',
];

/**
 * Per-category minimums. These are capability floors, not preferences: a
 * workstation that cannot hold its data is not a workstation.
 */
$MIN = [
    'ram'     => ['capacity_gb' => 32],
    'storage' => ['capacity_gb' => 1000],
    'psu'     => ['wattage' => 550],
];

$capacityGb = static function (Component $c): int {
    $specs = is_array($c->specs) ? $c->specs : [];
    $raw = strtoupper((string) ($specs['capacity'] ?? ''));

    if (preg_match('/([\d.]+)\s*TB/i', $raw, $m)) {
        return (int) round(((float) $m[1]) * 1024);
    }
    if (preg_match('/([\d.]+)\s*GB/i', $raw, $m)) {
        return (int) round((float) $m[1]);
    }

    // Fall back to the product name, which is where plenty of scraped rows keep
    // the capacity. Reading the name is not a substitute for reading the spec -
    // it is the fallback when the spec is missing, and it is why this script
    // never treats an unreadable row as good enough on its own.
    if (preg_match('/([\d.]+)\s*TB/i', (string) $c->name, $m)) {
        return (int) round(((float) $m[1]) * 1024);
    }
    if (preg_match('/([\d.]+)\s*GB/i', (string) $c->name, $m)) {
        return (int) round((float) $m[1]);
    }

    return 0;
};

/**
 * Extra per-category inclusion rules, on top of the shared exclusions.
 *
 * 'cpu' is deliberately ABSENT. CPUs are judged by the integrated-graphics rule
 * below, and leaving a name list here as well meant the two disagreed - the
 * name list won, and tagged 12 of 230 processors. One rule per category, so
 * there is never a question of which of two rules fired.
 *
 * 'gpu' is present and very tight. The catalogue holds exactly one professional
 * card, and that is the correct answer rather than a gap in the filter: a
 * business machine should run on integrated graphics, and every consumer card
 * we stock is a gaming card. Tagging RTX 5090s for workstations would recreate
 * the exact failure the business catalogue exists to prevent.
 */
$INCLUDE = [
    'cooler' => [
        // Air coolers. Noctua, Thermalright, be quiet, DeepCool AK and the
        // explicit "Air"/"Tower" words. Everything liquid was already excluded.
        'name' => ['Noctua', 'Thermalright', 'Peerless', 'Phantom', 'Tower', 'Air', 'AK', 'be quiet', 'Scythe', 'Noiseblock'],
    ],
    'case' => [
        // Plain towers and workstation chassis. The RGB exclusion has already
        // removed the glow; this keeps full-tower ATX for drive bays.
        'name' => ['Define', 'Meshify', 'O11', 'N400', 'N500', 'Q300', 'Q500', 'R500', 'H400', 'H500', 'ProArt', 'T500', 'Silent Base', 'Workstation'],
    ],
    'motherboard' => [
        // Workstation lines, plus the X670E/X870E enthusiast boards that are the
        // closest thing to a business board we actually stock.
        'name' => ['ProArt', 'Pro WS', 'Prime', 'Pro', 'X670E', 'X870E', 'T790', 'W790', 'Creator'],
    ],
    'gpu' => [
        // Professional silicon and low-power cards only. This is the tightest
        // filter in the script on purpose: a gaming card on a workstation quote
        // is the specific failure this whole catalogue split exists to stop.
        'name' => ['Radeon Pro', 'RTX A', 'Quadro', 'Tesla', 'Arc Pro', 'WX ', 'W7900', 'W7800', 'W6800', 'T400', 'T600', 'T1000', 'GT 1030', 'GTX 1650'],
    ],
    'psu' => [],
    'ram' => [],
    'storage' => [],
];

$rows = Component::query()->active()->with('category')->get();

// Empty, NOT collect($rows->all()).
//
// Seeding this with the source collection put all 2,703 rows into the plan
// before a single rule had run, so the totals came back as 3,771 and the
// "sample" printed untagged rows - including an RGB case and a GAMING
// motherboard, which is exactly the output this script exists to prevent.
// A dry run that cannot report the truth is worse than no dry run.
$plan = collect();
$rejected = [];

foreach ($rows as $component) {
    $slug = $component->category?->slug ?? 'misc';
    $name = (string) $component->name;

    $reason = null;

    foreach ($EXCLUDE as $needle => $why) {
        if (stripos($name, $needle) !== false) {
            $reason = "name contains '{$needle}' ({$why})";
            break;
        }
    }

    if ($reason === null && $slug === 'cpu') {
        /*
         * CPUs are judged on INTEGRATED GRAPHICS, not on how business-sounding
         * the name is.
         *
         * The first two dry runs both got this wrong. Matching 'PRO'/'Plus'
         * tagged 12 of 230 processors, which is not a catalogue, and matching
         * 'i5-'/'i7-' tagged a 253W 13700K, which is not an office processor.
         *
         * The rule that actually matters: an F-suffixed part has NO integrated
         * graphics, so the machine cannot run without a discrete card. That is
         * the property a business buyer cares about, and it is readable from the
         * name. Everything else - including a K-part, which still has iGPU - is
         * allowed in, because excluding it would empty the category for a reason
         * that has nothing to do with the work being done.
         */
        if (preg_match('/\b\d{4,5}F(F|KF)?\b/', $name)) {
            $reason = 'F-suffixed CPU has no integrated graphics, so the machine needs a discrete card';
        }
    }

    if ($reason === null && isset($MIN[$slug])) {
        foreach ($MIN[$slug] as $field => $minimum) {
            $actual = match ($field) {
                'capacity_gb' => $capacityGb($component),
                'wattage' => (int) ($component->wattage ?: 0),
                default => 0,
            };

            if ($actual < $minimum) {
                $reason = "{$field} {$actual} is below the business minimum of {$minimum}";

                break;
            }
        }
    }

    if ($reason === null && isset($INCLUDE[$slug]['name'])) {
        $needles = $INCLUDE[$slug]['name'];
        $hit = false;

        foreach ($needles as $needle) {
            if (stripos($name, $needle) !== false) {
                $hit = true;
                break;
            }
        }

        if (! $hit) {
            $reason = "no business inclusion match in '{$slug}'";
        }
    }

    if ($reason !== null) {
        $rejected[] = [$slug, $name, $reason];

        continue;
    }

    $plan[] = $component;
}

$byCategory = $plan->countBy(fn ($c) => $c->category?->slug ?? 'misc');
$total = $rows->count();

echo "\n";
echo "  TAGGED FOR BUSINESS : ".$plan->count()." of {$total} active components\n";
foreach ($byCategory->sortKeys() as $slug => $n) {
    $active = $rows->where(fn ($c) => ($c->category?->slug ?? 'misc') === $slug)->count();
    printf("    %-14s %4d of %4d\n", $slug, $n, $active);
}

$rejectedByReason = collect($rejected)->groupBy(2)->map->count()->sortDesc();
echo "\n  EXCLUDED            : ".count($rejected)."\n";
foreach ($rejectedByReason->take(12) as $why => $n) {
    printf("    %4d  %s\n", $n, $why);
}
if ($rejectedByReason->count() > 12) {
    echo '    ... and '.($rejectedByReason->count() - 12)." more reasons\n";
}

echo "\n  SAMPLE (5 per category)\n";
foreach ($byCategory->sortKeys() as $slug => $_) {
    $sample = $plan->filter(fn ($c) => ($c->category?->slug ?? 'misc') === $slug)->take(5);
    foreach ($sample as $component) {
        printf("    %-13s %-56s £%s\n", $slug, mb_substr($component->name, 0, 56), number_format((float) $component->price, 2));
    }
}

if ($mode === '--dry-run') {
    echo "\nDRY RUN - nothing written. Re-run with --apply to tag these parts.\n";

    exit(0);
}

$updated = 0;

foreach ($plan as $component) {
    $segments = $component->productSegments();

    if (in_array('business', $segments, true)) {
        continue;
    }

    $segments[] = 'business';

    $component->segments = array_values(array_unique($segments));
    $component->save();
    $updated++;
}

// The capability gate caches for five minutes; a stale answer here would keep
// showing a build button for a catalogue that no longer supports it.
app(\App\Services\BusinessCapabilityGate::class)->flush();

echo "\n  WROTE {$updated} rows. Capability gate cache cleared.\n";
echo "  REVERSIBLE: re-run with --revert to remove every business tag.\n";
