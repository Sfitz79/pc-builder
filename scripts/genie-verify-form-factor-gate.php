<?php
/*
 * READ-ONLY production verification of the form-factor gate.
 *
 * Deliberately NOT `php artisan test` against Neon. The suite is not built for
 * it, it hangs on connection setup, and pointing a test suite at production
 * risks writes. This replicates the assertions of the two Neon-gated tests
 * using read-only queries and the real service code.
 */
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use App\Services\CompatibilityCheckerService;
use App\Services\CompatibilityService;

$fail = 0;
$t = function (bool $ok, string $l) use (&$fail) {
    if (! $ok) {
        $fail++;
    }
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $l);
};

echo "=== equivalent of test_production_cases_have_verified_form_factors ===\n";
$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$cases = DB::table('components')->where('category_id', $caseId)->where('active', true)
    ->get(['id', 'name', 'specs']);
$t($cases->count() > 0, "active cases read: {$cases->count()}");

$svc = new CompatibilityCheckerService();
$m = new ReflectionMethod($svc, 'getCaseCompatibility');
$m->setAccessible(true);

$missing = [];
$wrong = [];
foreach ($cases as $r) {
    $s = is_string($r->specs) ? json_decode($r->specs, true) : $r->specs;
    $s = is_array($s) ? $s : [];
    $ff = $s['form_factor'] ?? null;
    $sup = $s['supported_form_factors'] ?? null;
    if (! is_string($ff) || $ff === '') {
        $missing[] = $r->id . ' ' . $r->name;
        continue;
    }
    $expected = $m->invoke($svc, $ff);
    if ($expected === null) {
        $wrong[] = $r->id . ' ' . $r->name . " (unknown FF $ff)";
    } elseif ($sup !== $expected) {
        $wrong[] = $r->id . ' ' . $r->name . ' (supported list disagrees with the matrix)';
    }
}
$t($missing === [], 'every active case has a verified form_factor' . ($missing ? ': ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$t($wrong === [], 'every supported_form_factors list matches the matrix' . ($wrong ? ': ' . implode(', ', array_slice($wrong, 0, 5)) : ''));

echo "\n=== equivalent of test_form_factor_rule_row_is_active ===\n";
$row = DB::table('compatibility_rules')->where('rule_type', 'form_factor_match')->where('active', true)->first();
$t($row !== null, 'an ACTIVE form_factor_match rule exists');
$t(($row->category ?? null) === 'form_factor', 'it uses the form_factor category summary() reads');

echo "\n=== board side ===\n";
$boardId = DB::table('categories')->where('name', 'Motherboard')->value('id');
$boards = DB::table('components')->where('category_id', $boardId)->where('active', true)->get(['id', 'name', 'specs']);
$bMissing = [];
foreach ($boards as $r) {
    $s = is_string($r->specs) ? json_decode($r->specs, true) : $r->specs;
    $s = is_array($s) ? $s : [];
    if (($s['form_factor'] ?? '') === '') {
        $bMissing[] = $r->id;
    }
}
printf("  active boards: %d\n", $boards->count());
$t($bMissing === [], 'every active board has specs.form_factor' . ($bMissing ? ': ' . implode(', ', array_slice($bMissing, 0, 5)) : ''));

echo "\n=== live behaviour through CompatibilityService (read-only) ===\n";
$pick = function (string $category, string $ff) {
    return DB::table('components')
        ->where('category_id', DB::table('categories')->where('name', $category)->value('id'))
        ->where('active', 1)->where('price', '>', 0)->get(['id', 'name', 'specs'])
        ->first(function ($r) use ($ff) {
            $s = is_string($r->specs) ? json_decode($r->specs, true) : $r->specs;
            $s = is_array($s) ? $s : [];

            return ($s['form_factor'] ?? '') === $ff;
        });
};

$svc2 = app(CompatibilityService::class);
$casesToCheck = [
    ['ATX', 'Mini-ITX', false],
    ['ATX', 'Micro-ATX', false],
    ['E-ATX', 'Mini-ITX', false],
    ['ATX', 'ATX', true],
    ['Mini-ITX', 'ATX', true],
    ['Micro-ATX', 'ATX', true],
];

foreach ($casesToCheck as [$bff, $cff, $want]) {
    $b = $pick('Motherboard', $bff);
    $c = $pick('Case', $cff);
    if (! $b || ! $c) {
        printf("  [SKIP] no %s board or %s case available\n", $bff, $cff);
        continue;
    }
    $sel = [
        'motherboard' => ['id' => $b->id, 'name' => $b->name, 'specs' => is_string($b->specs) ? json_decode($b->specs, true) : $b->specs],
        'case' => ['id' => $c->id, 'name' => $c->name, 'specs' => is_string($c->specs) ? json_decode($c->specs, true) : $c->specs],
    ];
    $got = (bool) $svc2->summary($sel)['formFactorFits'];
    printf("  [%s] %-10s board in a %-10s case -> formFactorFits=%s\n",
        $got === $want ? 'PASS' : 'FAIL', $bff, $cff, $got ? 'true' : 'false');
    if ($got !== $want) {
        $fail++;
    }
}

echo "\n" . ($fail === 0 ? "ALL PRODUCTION CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED\n");
exit($fail === 0 ? 0 : 1);