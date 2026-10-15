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

$connection = DB::connection();
$databaseUrl = $connection->getConfig('url');
$urlHost = is_string($databaseUrl) ? parse_url($databaseUrl, PHP_URL_HOST) : null;
$databaseHost = is_string($urlHost) ? $urlHost : $connection->getConfig('host');
if (! is_string($databaseHost) || ! preg_match('/(^|\.)neon\.tech$/i', $databaseHost)) {
    fwrite(STDERR, "genie-verify-form-factor-gate: ABORTING; the active PostgreSQL endpoint is not verified as Neon.\n");
    exit(4);
}
echo "[source] connected PostgreSQL endpoint verified as Neon\n";

use App\Services\CompatibilityCheckerService;
use App\Services\CompatibilityService;
use App\Models\CompatibilityRule;
use App\Models\Component;
use App\Services\CatalogueGate;

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
$socketRule = DB::table('compatibility_rules')
    ->where('category', 'cpu_motherboard')
    ->where('rule_type', 'socket_match')
    ->where('active', true)
    ->first();
$t($socketRule !== null, 'an ACTIVE socket_match rule exists for the builder summary');
$summaryRules = [
    'cpu_motherboard' => 'socket_match',
    'memory' => 'memory_supported',
    'power' => 'wattage_sufficient',
    'clearance' => 'clearance_check',
    'form_factor' => 'form_factor_match',
];
foreach ($summaryRules as $category => $type) {
    $active = DB::table('compatibility_rules')
        ->where('category', $category)
        ->where('rule_type', $type)
        ->where('active', true)
        ->exists();
    $t($active, "active {$type} rule exists for {$category} summary flag");
}

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

echo "\n=== normalized CPU / motherboard sockets ===\n";
$cpus = Component::query()->with('category')->active()
    ->whereHas('category', fn ($query) => $query->where('slug', 'cpu'))
    ->get();
$boardModels = Component::query()->with('category')->active()
    ->whereHas('category', fn ($query) => $query->where('slug', 'motherboard'))
    ->get();

$cpuSocketMismatches = 0;
foreach ($cpus as $cpu) {
    $derived = CatalogueGate::cpuSocket((string) $cpu->name);
    if ($derived !== CatalogueGate::UNKNOWN && $cpu->socket !== $derived) {
        $cpuSocketMismatches++;
    }
}
$boardSocketMismatches = 0;
foreach ($boardModels as $board) {
    $derived = CatalogueGate::boardSocket((string) $board->name);
    if ($derived !== CatalogueGate::UNKNOWN && $board->socket !== $derived) {
        $boardSocketMismatches++;
    }
}
printf("  active CPUs: %d; stored sockets disagree with derivation: %d\n", $cpus->count(), $cpuSocketMismatches);
printf("  active motherboards: %d; stored sockets disagree with derivation: %d\n", $boardModels->count(), $boardSocketMismatches);
$t($cpus->isNotEmpty() && $boardModels->isNotEmpty(), 'active CPUs and motherboards are available for pairing checks');

$mismatchPair = null;
$matchingPair = null;
$staleColumnPair = null;
foreach ($cpus as $cpu) {
    $cpuSocket = CatalogueGate::cpuSocket((string) $cpu->name);
    if ($cpuSocket === CatalogueGate::UNKNOWN) {
        continue;
    }
    foreach ($boardModels as $board) {
        $boardSocket = CatalogueGate::boardSocket((string) $board->name);
        if ($boardSocket === CatalogueGate::UNKNOWN) {
            continue;
        }
        if ($cpuSocket === $boardSocket && $matchingPair === null) {
            $matchingPair = [$cpu, $board];
        }
        if ($cpuSocket !== $boardSocket) {
            $mismatchPair ??= [$cpu, $board];
            if ($cpu->socket !== null && $cpu->socket === $board->socket) {
                $staleColumnPair ??= [$cpu, $board];
            }
        }
    }
}

$pair = $staleColumnPair ?? $mismatchPair;
if ($pair !== null) {
    [$cpu, $board] = $pair;
    $normalized = [
        'cpu' => app(CompatibilityService::class)->componentSelectionData($cpu),
        'motherboard' => app(CompatibilityService::class)->componentSelectionData($board),
    ];
    $socketRuleModel = CompatibilityRule::query()
        ->where('category', 'cpu_motherboard')
        ->where('rule_type', 'socket_match')
        ->where('active', true)
        ->first();
    if ($socketRuleModel !== null) {
        $pairPass = $socketRuleModel->evaluate($normalized);
        $t(! $pairPass, $staleColumnPair !== null
            ? 'a production pair with matching stale stored sockets is rejected using name-derived sockets'
            : 'a production pair with different derived sockets is rejected');
        $summary = app(CompatibilityService::class)->summary($normalized);
        $t($summary['cpuMotherboard'] === false, 'CompatibilityService summary reports the real mismatched pair as incompatible');
    }
} else {
    $t(false, 'a production CPU / motherboard pair with different recognized sockets exists');
}

if ($matchingPair !== null) {
    [$cpu, $board] = $matchingPair;
    $normalized = [
        'cpu' => app(CompatibilityService::class)->componentSelectionData($cpu),
        'motherboard' => app(CompatibilityService::class)->componentSelectionData($board),
    ];
    $socketRuleModel = CompatibilityRule::query()
        ->where('category', 'cpu_motherboard')
        ->where('rule_type', 'socket_match')
        ->where('active', true)
        ->first();
    if ($socketRuleModel !== null) {
        $t($socketRuleModel->evaluate($normalized), 'a production CPU / motherboard pair with the same derived socket passes');
        $t(app(CompatibilityService::class)->summary($normalized)['cpuMotherboard'] === true,
            'CompatibilityService summary reports the real matching pair as compatible');
    }
} else {
    $t(false, 'a production CPU / motherboard pair with the same recognized socket exists');
}

echo "\n=== live behaviour through CompatibilityService (read-only) ===\n";
$pick = function (string $category, string $ff) {
    return DB::table('components')
        ->where('category_id', DB::table('categories')->where('name', $category)->value('id'))
        ->where('active', true)->where('price', '>', 0)->get(['id', 'name', 'specs'])
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