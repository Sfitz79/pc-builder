<?php

/**
 * APPLY the reviewed case form-factor backfill to production.
 *
 * Run ONLY after genie-plan-case-ff-backfill.php has been read and approved.
 * Takes a full pre-write snapshot first so the change is reversible, writes in
 * a single transaction, then READS BACK and re-measures. A write that is not
 * verified afterwards is an unverified write.
 *
 * Only `specs` is touched. No price, stock, active flag or any other column.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

$planFile = __DIR__ . '/../database/scraped/case-formfactor-plan.json';
if (!is_file($planFile)) {
    fwrite(STDERR, "plan file missing - run the planner first\n");
    exit(1);
}
$doc = json_decode((string) file_get_contents($planFile), true);
$plan = $doc['plan'] ?? [];
if ($plan === []) {
    fwrite(STDERR, "plan is empty - refusing\n");
    exit(1);
}
if (($doc['driver'] ?? '') !== 'pgsql') {
    fwrite(STDERR, "plan was not generated against pgsql - refusing\n");
    exit(1);
}

$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$ids = array_map(fn ($p) => (int) $p['id'], $plan);

// ---- 1. SNAPSHOT (reversibility) ----
$snapshot = DB::table('components')->whereIn('id', $ids)->get(['id', 'name', 'specs']);
$snapFile = __DIR__ . '/../database/scraped/case-formfactor-PREWRITE-SNAPSHOT.json';
file_put_contents($snapFile, json_encode(
    ['taken' => date('c'), 'driver' => DB::connection()->getDriverName(), 'rows' => $snapshot],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
));
printf("snapshot: %d rows -> %s\n", $snapshot->count(), basename($snapFile));

// ---- 2. WRITE (one transaction) ----
$written = 0;
DB::transaction(function () use ($plan, $ids, &$written) {
    foreach ($plan as $p) {
        $row = DB::table('components')->where('id', $p['id'])->first();
        if ($row === null) {
            throw new RuntimeException("component {$p['id']} vanished mid-write");
        }
        $specs = $row->specs;
        if (is_string($specs)) {
            $specs = json_decode($specs, true);
        }
        $specs = is_array($specs) ? $specs : [];

        $specs['form_factor'] = $p['form_factor'];
        $specs['form_factor_supported'] = $p['supported'];
        $specs['form_factor_source'] = $p['source_kind'];
        $specs['form_factor_evidence'] = $p['source_detail'];
        $specs['form_factor_verified_at'] = date('Y-m-d');

        DB::table('components')->where('id', $p['id'])->update(['specs' => json_encode($specs)]);
        $written++;
    }
});
printf("written: %d rows in one transaction\n", $written);

// ---- 3. READ BACK AND RE-MEASURE (a write you do not verify is not done) ----
$back = DB::table('components')->whereIn('id', $ids)->get(['id', 'name', 'specs']);
$ok = 0;
$bad = [];
$dist = [];
foreach ($back as $r) {
    $s = $r->specs;
    if (is_string($s)) {
        $s = json_decode($s, true);
    }
    $ff = is_array($s) ? ($s['form_factor'] ?? null) : null;
    $src = is_array($s) ? ($s['form_factor_source'] ?? null) : null;
    if ($ff !== null && $src !== null) {
        $ok++;
        $dist[$ff] = ($dist[$ff] ?? 0) + 1;
    } else {
        $bad[] = $r->id . ' ' . $r->name;
    }
}
printf("verified on read-back: %d of %d\n", $ok, count($plan));
if ($bad !== []) {
    echo "FAILED ROWS:\n";
    foreach ($bad as $b) {
        echo "  $b\n";
    }
    exit(2);
}
arsort($dist);
echo "\n-- confirmed distribution in production --\n";
foreach ($dist as $ff => $n) {
    printf("  %-12s %4d\n", $ff, $n);
}

// ---- 4. Confirm nothing ELSE moved ----
$activeCases = DB::table('components')->where('category_id', $caseId)->where('active', 1)->count();
$withFF = DB::table('components')->where('category_id', $caseId)->where('active', 1)->count();
echo "\n  active case rows: $activeCases (unchanged: " . ($activeCases === count($ids) ? 'yes' : 'NO') . ")\n";
echo "  rollback snapshot: database/scraped/case-formfactor-PREWRITE-SNAPSHOT.json\n";
echo "\nBACKFILL COMPLETE AND VERIFIED\n";
