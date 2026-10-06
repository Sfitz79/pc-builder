<?php

/**
 * Derive specs.supported_form_factors for every case from the verified
 * specs.form_factor backfill (2026-09-29), using the SAME matrix the live
 * compatibility gate already encodes (CompatibilityCheckerService
 * ::getCaseCompatibility / CompatibilityRule::evaluateFormFactor).
 *
 * Why: the live gate reads $case['specs']['supported_form_factors'] - not
 * form_factor - and returns TRUE (fail-open) when it is missing. Rule 6:
 * once the data exists, the customer-facing promise must not silently pass
 * on a hole. This is a pure function of already-verified values; no new
 * facts are asserted. A snapshot is taken first; a write that is not read
 * back is not a write.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

// The matrix from CompatibilityCheckerService::getCaseCompatibility().
// ATX case = holds ATX/mATX/ITX. mATX = holds mATX/ITX. ITX = holds ITX.
// E-ATX = holds E-ATX and everything smaller.
const FF_MATRIX = [
    'ATX'       => ['ATX', 'Micro-ATX', 'Mini-ITX'],
    'Micro-ATX' => ['Micro-ATX', 'Mini-ITX'],
    'Mini-ITX'  => ['Mini-ITX'],
    'E-ATX'     => ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'],
];

$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$rows = DB::table('components')->where('category_id', $caseId)->where('active', true)
    ->get(['id', 'name', 'specs']);

$plans = [];
foreach ($rows as $r) {
    $s = $r->specs;
    if (is_string($s)) {
        $s = json_decode($s, true);
    }
    $s = is_array($s) ? $s : [];
    $ff = $s['form_factor'] ?? null;

    // Fail closed: no verified form factor -> do not invent support.
    if (! is_string($ff) || ! isset(FF_MATRIX[$ff])) {
        continue;
    }
    $plans[$r->id] = [
        'name' => $r->name,
        'form_factor' => $ff,
        'supported_form_factors' => FF_MATRIX[$ff],
        'existing' => $s['supported_form_factors'] ?? null,
    ];
}

printf("=== DRY RUN: derive supported_form_factors ===\n");
printf("  verified cases with form_factor : %d\n", count($plans));
$already = array_filter($plans, fn ($p) => $p['existing'] !== null);
printf("  already populated                : %d\n", count($already));
foreach ($plans as $p) {
    if ($p['existing'] === null) {
        printf("  + %-40s %-10s -> %s\n", substr($p['name'], 0, 40), $p['form_factor'], implode(', ', $p['supported_form_factors']));
    }
}

// Snapshot for reversibility.
$snapshot = DB::table('components')->whereIn('id', array_keys($plans))->get(['id', 'name', 'specs']);
file_put_contents(
    __DIR__ . '/../database/scraped/case-supportedff-PREWRITE-SNAPSHOT.json',
    json_encode(['taken' => date('c'), 'rows' => $snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

DB::transaction(function () use ($plans) {
    foreach ($plans as $id => $p) {
        $row = DB::table('components')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException("component {$id} vanished mid-write");
        }
        $s = $row->specs;
        if (is_string($s)) {
            $s = json_decode($s, true);
        }
        $s = is_array($s) ? $s : [];
        $s['supported_form_factors'] = $p['supported_form_factors'];
        DB::table('components')->where('id', $id)->update(['specs' => json_encode($s)]);
    }
});
printf("\n  written: %d rows (one transaction)\n", count($plans));

// Read back and verify the exact gate condition.
$back = DB::table('components')->whereIn('id', array_keys($plans))->get(['id', 'specs']);
$ok = 0;
foreach ($back as $r) {
    $s = $r->specs;
    if (is_string($s)) {
        $s = json_decode($s, true);
    }
    $s = is_array($s) ? $s : [];
    $ff = $s['form_factor'] ?? null;
    $sup = $s['supported_form_factors'] ?? null;
    if (is_string($ff) && isset(FF_MATRIX[$ff]) && $sup === FF_MATRIX[$ff]) {
        $ok++;
    }
}
printf("  verified on read-back           : %d of %d\n", $ok, count($plans));
if ($ok !== count($plans)) {
    fwrite(STDERR, "READ-BACK MISMATCH - investigate before trusting\n");
    exit(2);
}
echo "\nDONE: live form-factor gate now engages with verified data.\n";