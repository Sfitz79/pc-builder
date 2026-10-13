<?php

/**
 * What would activating the missing compatibility rules actually do?
 *
 * THE GAP
 * Production has ONE compatibility_rules row ("Motherboard fits case", form_factor_match),
 * but CompatibilityRule::evaluate() implements TEN rule types. CompatibilityService::summary()
 * reports five customer-facing checks, and only one of them - formFactorFits - has a rule behind
 * it. The other four fall back to their default:
 *
 *     'cpuMotherboard' => $results->get('cpu_motherboard', true),
 *     'ramSupported'    => $results->get('memory',        true),
 *     'powerEnough'     => $results->get('power',         true),
 *     'gpuClearance'    => $results->get('clearance',     true),
 *
 * A missing rule reports as PASS. So the compatibility panel we show customers is, for four of
 * its five checks, reporting a default rather than a verification.
 *
 * THIS PROBE WRITES NOTHING. It evaluates each rule type against every existing build by
 * constructing the rule in memory, so the blast radius is known BEFORE anything is seeded.
 * Seeding without measuring would be activating checks nobody has ever seen the output of.
 *
 * Usage: php scripts/genie-probe-compatibility-rules.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Build;
use App\Models\CompatibilityRule;
use Illuminate\Support\Facades\DB;

// rule_type => [category, human name]. The five categories summary() reports are first,
// because those are the ones a customer actually sees.
$wanted = [
    ['socket_match', 'cpu_motherboard', 'CPU socket matches motherboard socket'],
    ['memory_supported', 'memory', 'Memory type supported by the board'],
    ['wattage_sufficient', 'power', 'Power supply has enough headroom'],
    ['clearance_check', 'clearance', 'Graphics card fits the case'],
    ['cooler_mount', 'cooler', 'CPU cooler mounting matches the socket'],
    ['m2_compatibility', 'storage', 'M.2 drive supported by the board'],
    ['pcie_slot', 'pcie', 'Graphics card has a usable PCIe slot'],
    ['ram_speed_optimal', 'ram_speed', 'Memory speed is a sane match for the platform'],
    ['gpu_power_connector', 'gpu_power', 'Power supply has the connectors the GPU needs'],
];

$existing = DB::table('compatibility_rules')->pluck('rule_type')->all();
echo 'rules already in production : '.count($existing).' ('.implode(', ', $existing).")\n";
echo 'rule types the engine can evaluate : '.count($wanted)."\n\n";

$builds = Build::query()->with('components.category')->get();
echo 'builds to test against : '.$builds->count()."\n\n";

$verdicts = [];

foreach ($builds as $build) {
    $selection = [];
    foreach ($build->components as $component) {
        $key = $component->category?->slug ?? $component->pivot->category ?? null;
        if ($key) {
            $selection[$key] = $component->toArray();
        }
    }

    $row = ['id' => $build->id, 'name' => mb_substr((string) $build->name, 0, 28)];

    foreach ($wanted as [$type, $category, $label]) {
        $rule = new CompatibilityRule([
            'name' => $label,
            'category' => $category,
            'rule_type' => $type,
            'conditions' => json_encode(['fail_closed' => true]),
            'active' => true,
        ]);
        $rule->exists = false;

        try {
            $pass = $rule->evaluate($selection);
            $row[$type] = $pass ? 'pass' : 'FAIL';
        } catch (\Throwable $e) {
            $row[$type] = 'ERR:'.substr($e->getMessage(), 0, 40);
        }
    }

    $verdicts[] = $row;
}

$cols = array_merge(['id', 'name'], array_map(fn ($w) => $w[0], $wanted));
printf("  %-4s %-30s", 'id', 'build');
foreach ($wanted as $w) {
    printf(" %-14s", substr($w[0], 0, 13));
}
echo "\n";
echo '  '.str_repeat('-', 36 + (15 * count($wanted)))."\n";

$failCounts = array_fill_keys(array_map(fn ($w) => $w[0], $wanted), 0);
$errCounts = $failCounts;

foreach ($verdicts as $row) {
    printf("  %-4s %-30s", $row['id'], $row['name']);
    foreach ($wanted as $w) {
        $v = $row[$w[0]];
        printf(" %-14s", $v);
        if ($v === 'FAIL') {
            $failCounts[$w[0]]++;
        } elseif (str_starts_with($v, 'ERR')) {
            $errCounts[$w[0]]++;
        }
    }
    echo "\n";
}

echo "\n  SUMMARY\n";
foreach ($wanted as $w) {
    printf(
        "    %-20s fails=%d errors=%d\n",
        $w[0],
        $failCounts[$w[0]],
        $errCounts[$w[0]]
    );
}

echo "\n  A high ERROR count means the evaluator cannot handle a real selection, which is a\n";
echo "  different problem from a genuine incompatibility. Do not seed a rule that errors.\n";
