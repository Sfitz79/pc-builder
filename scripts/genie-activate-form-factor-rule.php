<?php
/*
 * Activate the form_factor_match compatibility rule.
 *
 * WHY: compatibility_rules held only four rows - socket_match,
 * wattage_sufficient, memory_supported, clearance_check. CompatibilityService
 * evaluates whatever rows are active, so CompatibilityRule::evaluateFormFactor()
 * was never called. The builder performed NO form-factor check at all: not
 * fail-open, absent. An ATX board could be paired with a Mini-ITX case.
 *
 * This is only safe NOW, and the ordering matters. The board data came from
 * specs.form_factor on 397/397 active motherboards, derived from the PCPP slug
 * each row already carried. Closing evaluateFormFactor() before that data
 * existed would have blocked 394 unknown boards against all 399 cases and taken
 * the builder offline.
 *
 * Reversible: set active = false on the row created here.
 */
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

echo "=== before ===\n";
foreach (DB::table('compatibility_rules')->orderBy('id')->get() as $r) {
    printf("  id=%-3s %-22s active=%s\n", $r->id, $r->rule_type, var_export($r->active, true));
}

$existing = DB::table('compatibility_rules')->where('rule_type', 'form_factor_match')->first();

if ($existing) {
    if (! $existing->active) {
        DB::table('compatibility_rules')->where('id', $existing->id)
            ->update(['active' => true, 'updated_at' => date('Y-m-d H:i:s')]);
        echo "\n  activated existing row id={$existing->id}\n";
    } else {
        echo "\n  already active, nothing to do\n";
    }
} else {
    $id = DB::table('compatibility_rules')->insertGetId([
        'name' => 'Motherboard fits case',
        'description' => 'The board form factor must be one the case physically accepts. '
            . 'Board form factor from the PCPP slug on the catalogue row; case acceptance from '
            . 'specs.supported_form_factors. Fails closed when either side is unknown.',
        'category' => 'form_factor',
        'rule_type' => 'form_factor_match',
        'conditions' => json_encode([
            'board_field' => 'specs.form_factor',
            'case_field' => 'specs.supported_form_factors',
            'fail_closed' => true,
        ]),
        'active' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "\n  inserted rule id={$id}\n";
}

echo "\n=== after ===\n";
foreach (DB::table('compatibility_rules')->orderBy('id')->get() as $r) {
    printf("  id=%-3s %-22s category=%-14s active=%s\n", $r->id, $r->rule_type, (string) $r->category, var_export($r->active, true));
}

// ---- prove the rule now actually runs and decides ---------------------
echo "\n=== end-to-end through BuilderController's path ===\n";
$cat = DB::table('categories')->pluck('id', 'name');
$pick = function (string $category, callable $test) {
    return DB::table('components')
        ->where('category_id', DB::table('categories')->where('name', $category)->value('id'))
        ->where('active', 1)->where('price', '>', 0)->get()
        ->first(function ($r) use ($test) {
            return $test(json_decode((string) $r->specs, true) ?: []);
        });
};

$atxBoard = $pick('Motherboard', fn ($s) => ($s['form_factor'] ?? '') === 'ATX');
$itxCase = $pick('Case', fn ($s) => ($s['form_factor'] ?? '') === 'Mini-ITX');
$matxCase = $pick('Case', fn ($s) => ($s['form_factor'] ?? '') === 'Micro-ATX');
$atxCase = $pick('Case', fn ($s) => ($s['form_factor'] ?? '') === 'ATX');

$svc = app(App\Services\CompatibilityService::class);

$scenarios = [
    ['ATX board  + Mini-ITX case  (must be BLOCKED)', $atxBoard, $itxCase, false],
    ['ATX board  + Micro-ATX case (must be BLOCKED)', $atxBoard, $matxCase, false],
    ['ATX board  + ATX case       (must be ALLOWED)', $atxBoard, $atxCase, true],
];

foreach ($scenarios as [$label, $b, $c, $wantPass]) {
    $selection = [
        'motherboard' => ['id' => $b->id, 'name' => $b->name, 'specs' => json_decode((string) $b->specs, true) ?: []],
        'case' => ['id' => $c->id, 'name' => $c->name, 'specs' => json_decode((string) $c->specs, true) ?: []],
    ];
    $summary = $svc->summary($selection);
    $got = (bool) $summary['formFactorFits'];
    printf(
        "  [%s] %-46s got=%s\n",
        $got === $wantPass ? 'PASS' : 'FAIL',
        $label,
        $got ? 'true' : 'false'
    );
}

echo "\n=== full summary payload for the blocked pair ===\n";
$summary = $svc->summary([
    'motherboard' => ['id' => $atxBoard->id, 'name' => $atxBoard->name, 'specs' => json_decode((string) $atxBoard->specs, true) ?: []],
    'case' => ['id' => $itxCase->id, 'name' => $itxCase->name, 'specs' => json_decode((string) $itxCase->specs, true) ?: []],
]);
foreach ($summary as $k => $v) {
    printf("  %-18s %s\n", $k, $v ? 'true' : 'false');
}