<?php
/*
 * Apply the VERIFIED case form-factor plan, which supersedes the coarse
 * case_type derivation in genie-backfill-case-form-factor.php.
 *
 * Why this exists: the 2026-09-29 plan (database/scraped/case-formfactor-plan.json)
 * holds 399/399 entries with real provenance - 391 from the PCPP product-slug
 * token, 8 from quoted manufacturer sheets - but the write it describes never
 * landed in production, which is why specs.form_factor measured 0/399. The
 * coarse case_type derivation applied 2026-10-08 covered 396 and deliberately
 * failed closed on 3. The plan resolves all 3 correctly, with evidence:
 *
 *   Silverstone FLP01       E-ATX  silverstonetek.com: "supports motherboards up
 *                                  to the SSI-CEB form factor" (SSI-CEB exceeds
 *                                  E-ATX, so E-ATX is the safe downward cap)
 *   Silverstone GD09 Type-C ATX    silverstonetek.com: "Supports SSI-CEB, ATX,
 *                                  Micro-ATX" - despite the "htpc" slug, which
 *                                  is why slug-only guessing would have been wrong
 *
 * Safety properties, all deliberate:
 *   - The plan's vocabulary is validated against FF_MATRIX. Anything outside it
 *     is SKIPPED and fails closed rather than written.
 *   - Plan rows whose component is no longer an active case are skipped.
 *   - Active cases absent from the plan are left alone (fail closed).
 *   - Only form_factor / supported_form_factors keys are touched; all other
 *     specs keys on the row are preserved.
 *
 * Usage: php scripts/genie-apply-case-form-factor-plan.php [--apply]
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once $root = __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

// From CompatibilityCheckerService::getCaseCompatibility() - do not redefine.
const FF_MATRIX = [
    'ATX'       => ['ATX', 'Micro-ATX', 'Mini-ITX'],
    'Micro-ATX' => ['Micro-ATX', 'Mini-ITX'],
    'Mini-ITX'  => ['Mini-ITX'],
    'E-ATX'     => ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'],
];

$apply = in_array('--apply', $argv ?? [], true);

/**
 * Coarse derivation from the dedicated case_type COLUMN, used only to DETECT
 * a conflict with the plan's slug-derived value. Not used to write.
 *
 * case_type is free text ("ATX Mid Tower", "MicroATX Mini Tower"), so the most
 * specific token must be tested first: "Mini ITX" contains "ATX", as does
 * "MicroATX".
 */
function canonicalFromCaseType(?string $caseType): ?string
{
    $t = strtolower(trim((string) $caseType));
    if ($t === '') {
        return null;
    }
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

$planPath = __DIR__ . '/../database/scraped/case-formfactor-plan.json';
if (! is_file($planPath)) {
    fwrite(STDERR, "plan file missing: {$planPath}\n");
    exit(1);
}
$planFile = json_decode(file_get_contents($planPath), true);
$plan = $planFile['plan'] ?? [];

echo "=== applying verified case form-factor plan ===\n";
printf("  plan file generated : %s\n", $planFile['generated'] ?? 'unknown');
printf("  plan entries        : %d\n", count($plan));

$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$active = DB::table('components')
    ->where('category_id', $caseId)->where('active', true)
    ->get(['id', 'name', 'specs', 'case_type'])
    ->keyBy('id');

printf("  active cases        : %d\n\n", $active->count());

$writes = 0;
$unchanged = 0;
$skipped = [];
$conflicts = [];
$wins = [];
$notActive = 0;
$notInPlan = 0;
$byFf = [];
$changed = [];
// id => resolved form factor. The write phase iterates THIS, never the raw
// plan, so the conflict guard cannot be bypassed on write. An earlier version
// re-derived decisions inside the write loop and silently overwrote the two
// conflict rows with the plan's unsafe ATX value.
$decisions = [];

foreach ($plan as $row) {
    $id = (int) ($row['id'] ?? 0);
    $ff = $row['form_factor'] ?? null;

    // Fail closed on vocabulary the matrix does not know.
    if (! is_string($ff) || ! isset(FF_MATRIX[$ff])) {
        $skipped[] = "id {$id} ({$row['name']}): unknown form_factor '{$ff}'";
        continue;
    }
    if (! $active->has($id)) {
        $notActive++;
        continue;
    }

    $cur = $active->get($id);
    $s = is_string($cur->specs) ? json_decode($cur->specs, true) : $cur->specs;
    $s = is_array($s) ? $s : [];

    // CONFLICT GUARD runs BEFORE the "already correct" short-circuit.
    //
    // Ordering matters: a conflict row that was previously overwritten with the
    // plan's unsafe value would otherwise match the plan, report "already
    // correct", and never be corrected. The guard must see every plan row.
    //
    // The plan's pcpp-slug rows extract a form factor from the product slug.
    // For a micro-ATX case the slug contains BOTH tokens, e.g.
    // "coolermaster-q300l-v2-micro-atx-case-q300l-v2", so a naive /atx/ match
    // yields "ATX" rather than "Micro-ATX". Where the plan disagrees with the
    // dedicated case_type column, that is exactly this parser bug - and
    // writing it would over-classify the case, claiming it accepts full-size
    // boards it cannot hold, which sells a build that does not fit. That is the
    // same failure mode the plan itself avoids for FLP01 by capping DOWN.
    //
    // So: on conflict, the COLUMN wins and is (re)written. It is both better
    // evidence than a slug token and the safer direction - a narrower
    // acceptance list can only block a build that genuinely does not fit,
    // whereas over-classifying sells one that will not assemble.
    // Manufacturer-quoted rows are exempt: they are direct evidence, not a
    // slug token, and are the highest-quality rows in the plan.
    $fromColumn = canonicalFromCaseType($cur->case_type);
    $existing = $s['form_factor'] ?? null;
    $sourceKind = (string) ($row['source_kind'] ?? '?');
    $isConflict = $fromColumn !== null && $existing !== null && $fromColumn !== $ff;

    if ($isConflict && $sourceKind !== 'manufacturer') {
        $conflicts[] = sprintf(
            '    %-34s plan=%-10s column=%-10s (case_type=%s) [%s] - COLUMN WINS',
            substr((string) $cur->name, 0, 34), $ff, $fromColumn,
            (string) $cur->case_type, $sourceKind
        );
        // Fall through with the column's value as the decision, so an already
        // corrupted ATX row is actively corrected back to Micro-ATX.
        $ff = $fromColumn;
    } elseif ($isConflict) {
        $wins[] = sprintf(
            '    %-34s column=%-10s plan=%-10s [manufacturer quote beats column]',
            substr((string) $cur->name, 0, 34), $fromColumn, $ff
        );
    }

    if (($s['form_factor'] ?? null) === $ff
        && ($s['supported_form_factors'] ?? null) === FF_MATRIX[$ff]) {
        $unchanged++;
        continue;
    }

    $writes++;
    $decisions[$id] = $ff;
    $byFf[$ff] = ($byFf[$ff] ?? 0) + 1;
    if (($s['form_factor'] ?? null) !== $ff) {
        $changed[] = sprintf(
            '    %-34s %-12s -> %-10s [%s]',
            substr((string) $cur->name, 0, 34),
            (string) ($s['form_factor'] ?? '(none)'),
            $ff,
            (string) ($row['source_kind'] ?? '?')
        );
    }
}

foreach ($active as $c) {
    $inPlan = false;
    foreach ($plan as $p) {
        if ((int) ($p['id'] ?? 0) === (int) $c->id) {
            $inPlan = true;
            break;
        }
    }
    if (! $inPlan) {
        $notInPlan++;
    }
}

printf("  rows to write       : %d\n", $writes);
printf("  already correct     : %d\n", $unchanged);
printf("  plan rows not active: %d  (skipped)\n", $notActive);
printf("  active cases not in plan: %d  (left alone, FAIL CLOSED)\n", $notInPlan);
printf("  vocabulary rejected : %d\n", count($skipped));
printf("  plan/column CONFLICTS   : %d  (column wins, plan slug ignored)
", count($conflicts));
printf("  manufacturer beats column: %d
", count($wins));

ksort($byFf);
echo "\n  form factors being written:\n";
foreach ($byFf as $ff => $n) {
    printf("    %-12s %4d  -> accepts %s\n", $ff, $n, implode(', ', FF_MATRIX[$ff]));
}

if ($skipped) {
    echo "\n  rejected:\n";
    foreach ($skipped as $s) {
        echo "    {$s}\n";
    }
}
if ($changed) {
    echo "\n  rows whose form_factor CHANGES (vs the coarse case_type derivation):\n";
    foreach ($changed as $c) {
        echo $c . "\n";
    }
}
if ($wins) {
    echo "\n  manufacturer evidence overrode the column:\n";
    foreach ($wins as $w) {
        echo $w . "\n";
    }
}
if ($conflicts) {
    echo "\n  CONFLICTS - plan (slug-derived) disagrees with case_type column.\n";
    echo "  Suspected slug parser bug: a micro-ATX slug contains both tokens, so\n";
    echo "  /atx/ matches ATX and loses the Micro- prefix. Writing it would claim\n";
    echo "  the case holds full-size boards when it does not. The column value is written instead.\n";
    foreach ($conflicts as $c) {
        echo $c . "\n";
    }
}

if (! $apply) {
    echo "\nDRY RUN. Nothing written. Re-run with --apply.\n";
    exit(0);
}

// ---- snapshot ---------------------------------------------------------
$ids = [];
foreach ($plan as $p) {
    if ($active->has((int) ($p['id'] ?? 0))) {
        $ids[] = (int) $p['id'];
    }
}
$snapshot = DB::table('components')->whereIn('id', $ids)->get(['id', 'name', 'specs', 'case_type', 'updated_at']);
$snapPath = __DIR__ . '/../database/scraped/case-form-factor-plan-PREWRITE-SNAPSHOT.json';
file_put_contents($snapPath, json_encode(
    ['taken' => date('c'), 'row_count' => $snapshot->count(), 'rows' => $snapshot],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
));
printf("\n  snapshot: %s (%d rows)\n", basename($snapPath), $snapshot->count());

// ---- write ------------------------------------------------------------
// Iterate $decisions (built by the planning phase), NOT the raw plan. The
// planning phase is where the conflict guard runs; re-deriving here would let
// a conflicting row through, which is exactly the bug this comment exists to
// prevent.
$written = 0;
foreach ($decisions as $id => $ff) {
    $s = is_string($active->get($id)->specs)
        ? json_decode($active->get($id)->specs, true)
        : $active->get($id)->specs;
    $s = is_array($s) ? $s : [];
    $s['form_factor'] = $ff;
    $s['supported_form_factors'] = FF_MATRIX[$ff];

    DB::table('components')->where('id', $id)->update([
        'specs' => json_encode($s),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $written++;
}
printf("  rows written: %d\n", $written);

// ---- verify from the DB ----------------------------------------------
$verify = DB::table('components')->where('category_id', $caseId)->where('active', true)->get(['specs']);
$ffOk = $sffOk = 0;
$unresolvedList = [];
foreach ($verify as $r) {
    $s = is_string($r->specs) ? json_decode($r->specs, true) : $r->specs;
    $s = is_array($s) ? $s : [];
    if (! empty($s['form_factor'])) {
        $ffOk++;
    } else {
        $unresolvedList[] = $r->id;
    }
    if (! empty($s['supported_form_factors'])) {
        $sffOk++;
    }
}
printf("\n=== post-write verification (read back from Neon) ===\n");
printf("  specs.form_factor present            : %d / %d\n", $ffOk, $verify->count());
printf("  specs.supported_form_factors present : %d / %d\n", $sffOk, $verify->count());
if ($unresolvedList) {
    printf("  still unresolved ids: %s\n", implode(', ', $unresolvedList));
}

foreach (['Silverstone FLP01', 'Silverstone GD09'] as $needle) {
    $row = DB::table('components')->where('name', 'like', $needle . '%')->first(['name', 'specs']);
    if ($row) {
        $s = is_string($row->specs) ? json_decode($row->specs, true) : $row->specs;
        printf(
            "  %-26s -> ff=%-10s accepts=%s\n",
            substr($row->name, 0, 26),
            $s['form_factor'] ?? '-',
            implode(', ', $s['supported_form_factors'] ?? [])
        );
    }
}