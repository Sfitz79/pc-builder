<?php
/*
 * Backfill specs.form_factor + specs.supported_form_factors on CASES from the
 * verified case_type COLUMN, which is 399/399 complete in production.
 *
 * WHY THIS EXISTS (measured against production Neon on 2026-10-08):
 *
 *   specs.form_factor             0/399 cases   <- both consumers read this
 *   specs.supported_form_factors  0/399 cases   <- CompatibilityRule.php:153
 *   case_type COLUMN            399/399 cases   <- nobody read this
 *
 * All 399 rows share one updated_at of 2026-10-01 14:21:42, a single bulk
 * write. So the form-factor backfill WAS applied to production - on 2026-10-01,
 * not the 2026-09-29 recorded in docs/genie-state.json - but it landed in the
 * case_type column, not in specs. The state file recorded the wrong field name
 * and has been corrected; this script projects the already-verified column onto
 * the key the consumers actually read.
 *
 * This is a projection of existing verified data, not new claims. It removes
 * nothing. Unmappable case types are left untouched and therefore fail CLOSED,
 * which is the direction we want (hard-won rule 6: unknown must never equal
 * permitted).
 *
 * NOTE on scope: this fixes the CASE side only. Motherboard form factor is
 * 3/397 in production with no dedicated column, so evaluateFormFactor() will
 * still return true for an unknown board. Tightening that is tracked
 * separately and must not be bundled in here, because failing closed on
 * 394 unknown boards would reject almost every legitimate build.
 *
 * Usage:
 *   php scripts/genie-backfill-case-form-factor.php          (dry run)
 *   php scripts/genie-backfill-case-form-factor.php --apply  (writes)
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

// From CompatibilityCheckerService::getCaseCompatibility() - do not redefine.
// ATX case holds ATX/mATX/ITX. mATX holds mATX/ITX. ITX holds ITX.
// E-ATX holds E-ATX and everything smaller.
const FF_MATRIX = [
    'ATX'       => ['ATX', 'Micro-ATX', 'Mini-ITX'],
    'Micro-ATX' => ['Micro-ATX', 'Mini-ITX'],
    'Mini-ITX'  => ['Mini-ITX'],
    'E-ATX'     => ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'],
];

/**
 * case_type is free text ("ATX Mid Tower", "MicroATX Mini Tower"), so match the
 * most specific token first: "Mini ITX" must be tested before plain "ATX"
 * because it contains it, and "MicroATX" likewise.
 *
 * Returns a canonical FF_MATRIX key, or null to fail closed.
 */
function canonicalFromCaseType(?string $caseType): ?string
{
    $t = strtolower(trim((string) $caseType));
    if ($t === '') {
        return null;
    }
    // Deliberately NO mapping for HTPC or rackmount: an HTPC chassis may be
    // Mini-ITX, proprietary or anything else, and a 5U rackmount is not an
    // E-ATX board. Guessing either would manufacture a fit guarantee.
    if (str_contains($t, 'mini itx') || str_contains($t, 'mini-itx')) {
        return 'Mini-ITX';
    }
    if (str_contains($t, 'microatx') || str_contains($t, 'micro-atx') || str_contains($t, 'matx')) {
        return 'Micro-ATX';
    }
    if (str_contains($t, 'e-atx') || str_contains($t, 'eatx')) {
        return 'E-ATX';
    }
    if (str_contains($t, 'atx')) {
        return 'ATX';
    }
    return null;
}

$apply = in_array('--apply', $argv ?? [], true);

$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$rows = DB::table('components')
    ->where('category_id', $caseId)->where('active', true)
    ->orderBy('id')
    ->get(['id', 'name', 'case_type', 'specs']);

$plans = [];
$unmapped = [];
$already = 0;

foreach ($rows as $r) {
    $s = $r->specs;
    $s = is_string($s) ? json_decode($s, true) : $s;
    $s = is_array($s) ? $s : [];

    $ff = canonicalFromCaseType($r->case_type);

    if ($ff === null) {
        $unmapped[] = $r;
        continue;
    }

    // Already correct? Count it, do not rewrite it.
    if (($s['form_factor'] ?? null) === $ff
        && ($s['supported_form_factors'] ?? null) === FF_MATRIX[$ff]) {
        $already++;
        continue;
    }

    $plans[$r->id] = [
        'id' => $r->id,
        'name' => $r->name,
        'case_type' => $r->case_type,
        'from' => [
            'form_factor' => $s['form_factor'] ?? null,
            'supported_form_factors' => $s['supported_form_factors'] ?? null,
        ],
        'form_factor' => $ff,
        'supported_form_factors' => FF_MATRIX[$ff],
        'specs' => $s,
    ];
}

printf("=== case form-factor backfill from verified case_type column ===\n");
printf("  active cases                       : %d\n", $rows->count());
printf("  already correct, untouched         : %d\n", $already);
printf("  rows to write                      : %d\n", count($plans));
printf("  unmapped -> left alone, FAIL CLOSED : %d\n", count($unmapped));

$byFf = [];
foreach ($plans as $p) {
    $byFf[$p['form_factor']] = ($byFf[$p['form_factor']] ?? 0) + 1;
}
ksort($byFf);
echo "\n  derivation breakdown:\n";
foreach ($byFf as $ff => $n) {
    printf("    %-12s %4d  -> accepts %s\n", $ff, $n, implode(', ', FF_MATRIX[$ff]));
}

if ($unmapped) {
    echo "\n  unmapped cases (NOT written, will fail closed):\n";
    foreach ($unmapped as $u) {
        printf("    %-46s case_type=%s\n", substr($u->name, 0, 46), $u->case_type);
    }
}

echo "\n  sample of changes:\n";
foreach (array_slice($plans, 0, 6, true) as $p) {
    printf(
        "    %-40s %-18s -> ff=%-10s accepts=%s\n",
        substr($p['name'], 0, 40),
        $p['case_type'],
        $p['form_factor'],
        implode(', ', $p['supported_form_factors'])
    );
}

if (! $apply) {
    echo "\nDRY RUN. Nothing written. Re-run with --apply to write.\n";
    exit(0);
}

// ---- Snapshot before any write (reversibility) ------------------------
$snapshot = DB::table('components')
    ->whereIn('id', array_keys($plans))
    ->get(['id', 'name', 'specs', 'case_type', 'updated_at']);

$snapPath = __DIR__ . '/../database/scraped/case-form-factor-PREWRITE-SNAPSHOT.json';
file_put_contents($snapPath, json_encode(
    ['taken' => date('c'), 'row_count' => $snapshot->count(), 'rows' => $snapshot],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
));
printf("\n  snapshot written: %s (%d rows)\n", basename($snapPath), $snapshot->count());

// ---- Write -------------------------------------------------------------
$written = 0;
foreach ($plans as $p) {
    $s = $p['specs'];
    $s['form_factor'] = $p['form_factor'];
    $s['supported_form_factors'] = $p['supported_form_factors'];

    DB::table('components')->where('id', $p['id'])->update([
        'specs' => json_encode($s),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $written++;
}

printf("  rows written: %d\n", $written);

// ---- Verify against the DB, not against our own array ----------------
$caseId2 = DB::table('categories')->where('name', 'Case')->value('id');
$verify = DB::table('components')
    ->where('category_id', $caseId2)->where('active', true)
    ->get(['specs']);

$ffOk = $sffOk = 0;
foreach ($verify as $r) {
    $s = is_string($r->specs) ? json_decode($r->specs, true) : $r->specs;
    $s = is_array($s) ? $s : [];
    if (! empty($s['form_factor'])) { $ffOk++; }
    if (! empty($s['supported_form_factors'])) { $sffOk++; }
}
printf("\n=== post-write verification (read back from Neon) ===\n");
printf("  specs.form_factor present            : %d / %d\n", $ffOk, $verify->count());
printf("  specs.supported_form_factors present : %d / %d\n", $sffOk, $verify->count());
printf("  expected non-zero (399 - %d unmapped) : %d\n", count($unmapped), $verify->count() - count($unmapped));

echo "\n  NOTE: the live builder gate is still fail-open on the BOARD side\n";
echo "  (motherboard form factor is 3/397 with no source column). Cases are\n";
echo "  now verified; boards are not. Tracked separately.\n";