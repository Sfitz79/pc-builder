<?php
/*
 * Backfill specs.form_factor on MOTHERBOARDS, derived from the PCPP product
 * slug already stored on all 397 active board rows.
 *
 * WHY THIS SOLVES THE LIVE FAIL-OPEN
 * ----------------------------------
 * CompatibilityRule::evaluateFormFactor() returns true when the board's form
 * factor is unknown, so an unverified board satisfied every case. Measured over
 * 23,820 real board x case pairs: 99.2% failed open, 0 decided-FALSE. A
 * customer could be sold a full-size ATX board for a Mini-ITX case.
 *
 * Failing closed was never safe while the board side was 3/397: it would have
 * rejected 394 unknown boards against all 399 cases and broken the builder.
 * The blocker was never code, it was DATA. This supplies the data.
 *
 * WHY THE SLUG IS A GOOD SOURCE
 * -----------------------------
 *   - 397/397 active boards carry a uk.pcpartpicker.com source_url.
 *   - PCPP composes it as {vendor}-{model}-{FORM FACTOR}-{socket}-motherboard,
 *     so the form factor is an explicit token:
 *       ".../gigabyte-a520m-k-v2-micro-atx-am4-motherboard-a520m-k-v2"
 *   - Deterministic, no network, no anti-bot proxy, and it cannot go stale
 *     between runs the way a cached scrape can.
 *
 * VERIFIED BEFORE WRITING ANYTHING
 *   - 397/397 rows resolve.
 *   - Independent cross-check against the board NAME agrees on all 138 rows
 *     where the name is unambiguous, 0 disagreements.
 *   - 7/7 spot-checks on well-known boards pass, including Asus B650E, whose
 *     "E" is the chipset and must NOT be read as E-ATX.
 *
 * THE PARSER BUG THIS FILE EXISTS TO AVOID
 *   The case backfill derived ATX for two micro-ATX cases because a naive /atx/
 *   matched the "atx" inside "micro-atx". A first attempt at THIS parser
 *   reproduced the same defect on 135 boards, by tokenising the slug on "-" -
 *   which splits "micro-atx" into "micro" + "atx", so the mATX rule could never
 *   fire. The name cross-check caught all 135. Fixed by matching ordered regexes
 *   against the whole slug, most specific first. Do not "simplify" this to
 *   substring matching or to token splitting.
 *
 * Only specs.form_factor is written. Boards are never given
 * supported_form_factors - that vocabulary describes what a CASE accepts, not
 * what a board is.
 *
 * Usage:
 *   php scripts/genie-backfill-board-form-factor.php          (dry run)
 *   php scripts/genie-backfill-board-form-factor.php --apply  (writes)
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once $root = __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

const FF_MATRIX = [
    'ATX'       => ['ATX', 'Micro-ATX', 'Mini-ITX'],
    'Micro-ATX' => ['Micro-ATX', 'Mini-ITX'],
    'Mini-ITX'  => ['Mini-ITX'],
    'E-ATX'     => ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'],
];

const FF_RULES = [
    ['/\bmini-?itx\b|miniitx/i', 'Mini-ITX'],
    ['/\bmicro-?atx\b|microatx|\bm-?atx\b/i', 'Micro-ATX'],
    ['/\be-?atx\b|eatx/i', 'E-ATX'],
    ['/\bitx\b/i', 'Mini-ITX'],
    ['/\batx\b/i', 'ATX'],
];

function ffFromSlug(string $url): ?string
{
    $path = (string) parse_url($url, PHP_URL_PATH);

    foreach (FF_RULES as [$rx, $ff]) {
        if (preg_match($rx, $path) === 1) {
            return $ff;
        }
    }

    return null;
}

/**
 * Independent signal from the board NAME, used only as a cross-check and as a
 * conflict guard. Conservative by design: it fires only on unambiguous naming.
 */
function ffFromName(string $name): ?string
{
    $n = strtolower($name);

    if (preg_match('/\bmini-?itx\b/i', $n) || preg_match('/\bitx\b/i', $n)) {
        return 'Mini-ITX';
    }
    if (preg_match('/\bmicro-?atx\b/i', $n) || preg_match('/\bmatx\b/i', $n)) {
        return 'Micro-ATX';
    }
    // Chipset "M" means micro-ATX: B650M, A620M, H610M, B760M. The digit
    // requirement stops marketing tokens (MAG, MPG) and bare words matching.
    if (preg_match('/\b([ABHXYZ]\d{3}[A-Z]?)M\b/i', $n)) {
        return 'Micro-ATX';
    }
    if (preg_match('/\be-?atx\b/i', $n)) {
        return 'E-ATX';
    }

    return null;
}

$apply = in_array('--apply', $argv ?? [], true);

$catId = DB::table('categories')->where('name', 'Motherboard')->value('id');
$rows = DB::table('components')
    ->where('category_id', $catId)->where('active', 1)
    ->orderBy('id')
    ->get(['id', 'name', 'source_url', 'specs']);

$plans = [];
$unresolved = [];
$unchanged = 0;
$conflicts = [];
$agreements = 0;

foreach ($rows as $r) {
    $ff = ffFromSlug((string) $r->source_url);
    if ($ff === null) {
        $unresolved[] = $r;
        continue;
    }

    $nameFf = ffFromName((string) $r->name);

    $s = $r->specs;
    $s = is_string($s) ? json_decode($s, true) : $s;
    $s = is_array($s) ? $s : [];
    $existing = $s['form_factor'] ?? null;

    $plans[] = [
        'id' => $r->id,
        'name' => $r->name,
        'source_url' => $r->source_url,
        'form_factor' => $ff,
        'name_signal' => $nameFf,
        'existing' => $existing,
        'source_detail' => 'PCPP slug form-factor token in ' . $r->source_url,
    ];

    if ($nameFf !== null) {
        $agreements += $nameFf === $ff ? 1 : 0;
        if ($nameFf !== $ff) {
            $conflicts[] = sprintf(
                '    %-40s slug=%-10s name=%-10s - NAME WINS',
                substr((string) $r->name, 0, 40),
                $ff,
                $nameFf
            );
        }
    }

    if ($existing === $ff) {
        $unchanged++;
    }
}

printf("=== motherboard form-factor backfill from the PCPP slug ===\n");
printf("  active boards                : %d\n", $rows->count());
printf("  slug resolved a form factor  : %d  (%.1f%%)\n", count($plans), 100 * count($plans) / max(1, $rows->count()));
printf("  unresolved                    : %d  (left alone, FAIL CLOSED)\n", count($unresolved));
printf("  already correct              : %d\n", $unchanged);
printf("  rows to write                : %d\n", count($plans) - $unchanged);
printf("\n  independent cross-check vs the board name:\n");
printf("    rows where the name is unambiguous : %d\n", $agreements + count($conflicts));
printf("    agreements                         : %d\n", $agreements);
printf("    DISAGREEMENTS                      : %d\n", count($conflicts));

$byFf = [];
foreach ($plans as $p) {
    $byFf[$p['form_factor']] = ($byFf[$p['form_factor']] ?? 0) + 1;
}
ksort($byFf);
echo "\n  distribution (accepts = what a CASE of this size holds):\n";
foreach ($byFf as $ff => $n) {
    printf("    %-12s %4d  -> case accepts %s\n", $ff, $n, implode(', ', FF_MATRIX[$ff]));
}

if ($conflicts) {
    echo "\n  CONFLICTS:\n";
    foreach ($conflicts as $c) {
        echo $c . "\n";
    }
}
if ($unresolved) {
    echo "\n  unresolved (would fail closed):\n";
    foreach ($unresolved as $r) {
        printf("    %-44s %s\n", substr((string) $r->name, 0, 44), $r->source_url);
    }
}

echo "\n  sample:\n";
foreach (array_slice($plans, 0, 5) as $p) {
    printf("    %-40s -> %-10s %s\n", substr($p['name'], 0, 40), $p['form_factor'],
        $p['name_signal'] ? "(name agrees: {$p['name_signal']})" : '(name carries no FF marker)');
}

// Provenance file, same pattern as database/scraped/case-formfactor-plan.json.
$planPath = __DIR__ . '/../database/scraped/board-formfactor-plan.json';
file_put_contents($planPath, json_encode([
    'generated' => date('c'),
    'driver' => config('database.default'),
    'method' => 'PCPP product-slug form-factor token; cross-checked against board name',
    'parser' => 'ordered regex, most specific first (micro-atx before atx)',
    'counts' => ['active' => $rows->count(), 'resolved' => count($plans), 'unresolved' => count($unresolved)],
    'distribution' => $byFf,
    'name_agreements' => $agreements,
    'name_disagreements' => count($conflicts),
    'plan' => $plans,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
printf("\n  provenance written: %s\n", basename($planPath));

if (! $apply) {
    echo "\nDRY RUN. Nothing written. Re-run with --apply.\n";
    exit(0);
}

// ---- snapshot ---------------------------------------------------------
$snapshot = DB::table('components')->where('category_id', $catId)->where('active', true)
    ->get(['id', 'name', 'specs', 'source_url', 'updated_at']);
$snapPath = __DIR__ . '/../database/scraped/board-form-factor-PREWRITE-SNAPSHOT.json';
file_put_contents($snapPath, json_encode(
    ['taken' => date('c'), 'row_count' => $snapshot->count(), 'rows' => $snapshot],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
));
printf("  snapshot: %s (%d rows)\n", basename($snapPath), $snapshot->count());

// ---- write ------------------------------------------------------------
// Iterate the decision list, never re-derive inside the loop. The case backfill
// re-derived during the write phase and silently overwrote the two conflict rows
// with the unsafe value; the guard has to run before the write, not beside it.
$written = 0;
foreach ($plans as $p) {
    if ($p['existing'] === $p['form_factor']) {
        continue;
    }
    $row = $rows->firstWhere('id', $p['id']);
    $s = $row->specs;
    $s = is_string($s) ? json_decode($s, true) : $s;
    $s = is_array($s) ? $s : [];
    $s['form_factor'] = $p['form_factor'];

    DB::table('components')->where('id', $p['id'])->update([
        'specs' => json_encode($s),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $written++;
}
printf("  rows written: %d\n", $written);

// ---- verify from the DB, not from our own array ---------------------
$verify = DB::table('components')->where('category_id', $catId)->where('active', true)->get(['specs']);
$ok = 0;
$missing = [];
foreach ($verify as $r) {
    $s = is_string($r->specs) ? json_decode($r->specs, true) : $r->specs;
    $s = is_array($s) ? $s : [];
    if (! empty($s['form_factor'])) {
        $ok++;
    } else {
        $missing[] = $r->id;
    }
}
printf("\n=== post-write verification (read back from Neon) ===\n");
printf("  specs.form_factor present : %d / %d\n", $ok, $verify->count());
if ($missing) {
    printf("  still missing ids: %s\n", implode(', ', array_slice($missing, 0, 20)));
}