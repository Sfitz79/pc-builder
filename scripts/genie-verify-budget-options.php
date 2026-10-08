<?php
/*
 * READ-ONLY verification of the three-option budget layer against production.
 *
 * Checks, for a spread of budgets and resolutions:
 *   - all three slots are produced where they can be
 *   - every part passes BuildPolicyGate, so an AI quote can never contain a
 *     part the storefront would refuse to sell
 *   - under_budget is genuinely under the ask
 *   - at_budget is within the +/- GBP 150 tolerance
 *   - best_value is at least as good on the performance proxy as the others
 */
use App\Services\BudgetOptions;
use App\Services\BuildPolicyGate;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

if (! str_contains((string) config('database.connections.pgsql.host'), 'neon.tech')) {
    echo "ABORT: not on Neon\n";
    exit(1);
}

$policy = new BuildPolicyGate();
$svc = app(BudgetOptions::class);
$tolerance = 150.0;
$fail = 0;

$gatePart = function (string $type, object $row, string $socket) use ($policy) {
    return match (strtolower($type)) {
        'cpu' => $policy->cpuAllowed($row),
        'motherboard' => $policy->boardAllowed($row),
        'ram' => $policy->ramAllowed($row, $socket),
        'storage' => $policy->storageAllowed($row),
        'gpu' => $policy->gpuAllowed($row),
        default => ['ok' => true, 'reason' => null],
    };
};

foreach ([[1000, '1080P'], [1500, '1080P'], [2000, '1440P'], [2800, '1440P'], [4000, '4K'], [800, '1080P']] as [$budget, $res]) {
    $options = $svc->options((float) $budget, $res);
    printf("\n=== budget GBP %.0f / %s -> %d options ===\n", $budget, $res, count($options));

    if ($options === []) {
        echo "  none produced\n";

        continue;
    }

    $rows = [];
    foreach ($options as $slot => $opt) {
        $ok = true;
        $reasons = [];
        foreach ($opt['components'] as $c) {
            $row = DB::table('components')->find($c['id']);
            if (! $row) {
                $ok = false;
                $reasons[] = 'part id ' . $c['id'] . ' does not resolve';

                continue;
            }
            $g = $gatePart($c['type'], $row, 'AM5');
            if (! $g['ok']) {
                $ok = false;
                $reasons[] = $c['type'] . ': ' . $g['reason'];
            }
        }

        printf(
            "  %-13s GBP %-9.2f %s%s\n",
            $slot,
            $opt['total'],
            $ok ? 'all parts pass every gate' : 'GATE FAIL: ' . implode('; ', $reasons),
            $opt['integrated_graphics'] ? '  [no discrete GPU]' : ''
        );
        if (! $ok) {
            $fail++;
        }

        $rows[$slot] = $opt;
    }

    if (isset($rows['under_budget'])) {
        $good = $rows['under_budget']['total'] <= $budget;
        printf("    under_budget is under the ask: %s\n", $good ? 'yes' : 'NO (over by ' . ($rows['under_budget']['total'] - $budget) . ')');
        if (! $good && $rows['under_budget']['total'] > $budget) {
            $fail++;
        }
    }
    if (isset($rows['at_budget'])) {
        $d = abs($rows['at_budget']['difference']);
        $good = $d <= $tolerance;
        printf("    at_budget distance GBP %.2f (tolerance %.0f): %s\n", $d, $tolerance, $good ? 'within' : 'OUT OF TOLERANCE');
        if (! $good) {
            $fail++;
        }
    } else {
        echo "    at_budget omitted - no candidate landed within tolerance (honest, not padded)\n";
    }
    if (isset($rows['best_value'])) {
        $score = fn ($o) => ($o['gpu_tier'] * 10) + $o['cores'];
        $bestScore = $score($rows['best_value']);
        $others = collect($rows)->map($score)->max();
        $ok = $bestScore >= $others;
        printf("    best_value proxy score %.0f vs best-of-others %.0f: %s\n", $bestScore, $others, $ok ? 'ranks highest' : 'DOES NOT RANK HIGHEST');
        if (! $ok) {
            $fail++;
        }
    }
}

printf("\n%s\n", $fail === 0 ? "ALL BUDGET-LAYER CHECKS PASSED" : "{$fail} CHECK(S) FAILED");
exit($fail === 0 ? 0 : 1);