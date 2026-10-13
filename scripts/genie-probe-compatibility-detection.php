<?php

/**
 * Can the compatibility rules actually detect a BAD build?
 *
 * WHY THIS IS NECESSARY
 * ---------------------
 * The previous probe showed all 9 unseeded rule types passing against all 5 real builds. That
 * is not proof of correctness. CompatibilityRule is explicitly written so that a rule "must
 * not block a half-finished build" - evaluateFormFactor() returns TRUE when either part is
 * absent, and the other evaluators behave the same way. So an all-pass result is exactly what
 * you would see from evaluators that do nothing at all.
 *
 * A check that cannot go red is not a check. This builds DELIBERATELY BROKEN selections and
 * asserts the corresponding rule FAILS. Nothing is written.
 *
 * Usage: php scripts/genie-probe-compatibility-detection.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CompatibilityRule;
use Illuminate\Support\Facades\DB;

function rule(string $type, string $category, string $name): CompatibilityRule
{
    $rule = new CompatibilityRule([
        'name' => $name,
        'category' => $category,
        'rule_type' => $type,
        'conditions' => json_encode(['fail_closed' => true]),
        'active' => true,
    ]);
    $rule->exists = false;

    return $rule;
}

$pass = 0;
$fail = 0;

function expect(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label".($detail !== '' ? "  ($detail)" : '')."\n";
    }
}

echo "\nCAN THE RULES DETECT A BAD BUILD?\n\n";

// --- 1. AM5 CPU on an AM4 motherboard: the classic fatal mismatch -------------------
$socketRule = rule('socket_match', 'cpu_motherboard', 'CPU socket matches motherboard socket');
$cpuAm5 = ['name' => 'AMD Ryzen 7 7800X3D', 'socket' => 'AM5', 'specs' => ['cores' => 8, 'socket' => 'AM5']];
$boardAm4 = ['name' => 'ASRock B550M Pro4', 'socket' => 'AM4', 'specs' => ['socket' => 'AM4', 'form_factor' => 'mATX', 'memory_type' => 'DDR4', 'm2_slots' => []]];
$cpuAm4 = ['name' => 'AMD Ryzen 5 5600', 'socket' => 'AM4', 'specs' => ['cores' => 6, 'socket' => 'AM4']];
$boardAm5 = ['name' => 'ASUS ROG STRIX X670E', 'socket' => 'AM5', 'specs' => ['socket' => 'AM5', 'form_factor' => 'ATX', 'memory_type' => 'DDR5', 'm2_slots' => 4]];

$mismatch = $socketRule->evaluate(['cpu' => $cpuAm5, 'motherboard' => $boardAm4]);
expect('socket_match REJECTS an AM5 CPU on an AM4 board', $mismatch === false, 'got '.var_export($mismatch, true));

$match = $socketRule->evaluate(['cpu' => $cpuAm4, 'motherboard' => $boardAm4]);
expect('socket_match ACCEPTS an AM4 CPU on an AM4 board', $match === true, 'got '.var_export($match, true));

// --- 2. A deliberately undersized PSU for a big GPU ---------------------------------
// SHAPE NOTE: evaluateWattage reads wattage at the TOP LEVEL ($gpu['wattage'], $psu['wattage'],
// $cpu['wattage']) because those are real columns on the components table. An earlier draft of
// this probe passed specs.tdp instead, the lookups all resolved to 0, and the rule "passed" a
// 350W PSU on a 360W GPU. The rule was fine; the probe was wrong.
$wattRule = rule('wattage_sufficient', 'power', 'Power supply has enough headroom');
$bigGpu = ['name' => 'NVIDIA GeForce RTX 5080', 'wattage' => 360, 'specs' => ['wattage' => 360]];
$hungryCpu = ['name' => 'AMD Ryzen 9', 'wattage' => 150, 'specs' => ['wattage' => 150]];
$weakPsu = ['name' => '350W PSU', 'wattage' => 350, 'specs' => ['wattage' => 350]];
$bigPsu = ['name' => '850W PSU', 'wattage' => 850, 'specs' => ['wattage' => 850]];

$weak = $wattRule->evaluate(['gpu' => $bigGpu, 'psu' => $weakPsu, 'cpu' => $hungryCpu]);
expect('wattage_sufficient REJECTS a 350W PSU for a 360W GPU + 150W CPU', $weak === false, 'got '.var_export($weak, true));

$strong = $wattRule->evaluate(['gpu' => $bigGpu, 'psu' => $bigPsu, 'cpu' => $hungryCpu]);
expect('wattage_sufficient ACCEPTS an 850W PSU for that pair', $strong === true, 'got '.var_export($strong, true));

// --- 3. ATX board in an mATX-only case ----------------------------------------------
$ffRule = rule('form_factor_match', 'form_factor', 'Motherboard fits case');
$atxBoard = ['name' => 'ATX board', 'specs' => ['form_factor' => 'ATX']];
$smallCase = ['name' => 'Tiny case', 'specs' => ['supported_form_factors' => ['mATX']]];

$noFit = $ffRule->evaluate(['motherboard' => $atxBoard, 'case' => $smallCase]);
expect('form_factor_match REJECTS an ATX board in an mATX-only case', $noFit === false, 'got '.var_export($noFit, true));

$fits = $ffRule->evaluate(['motherboard' => $atxBoard, 'case' => ['name' => 'Big case', 'specs' => ['supported_form_factors' => ['ATX', 'mATX']]]]);
expect('form_factor_match ACCEPTS an ATX board in a full tower', $fits === true, 'got '.var_export($fits, true));

// --- 4. DDR5 memory on an AM4 (DDR4-only) board -------------------------------------
// SHAPE NOTE: evaluateMemory infers the generation from a DDR4/DDR5 marker inside the memory
// SPEED string, not from a memory_type field. The earlier draft of this probe passed
// specs.memory_type, which is never read, so the marker was never found.
$memRule = rule('memory_supported', 'memory', 'Memory type supported by the board');
$ddr5 = ['name' => 'Corsair Vengeance 32GB DDR5-6000', 'speed' => 'DDR5 6000', 'specs' => ['speed' => 'DDR5 6000']];
$ddr4 = ['name' => 'Corsair Vengeance 32GB DDR4-3200', 'speed' => 'DDR4 3200', 'specs' => ['speed' => 'DDR4 3200']];
$am4Board = ['name' => 'B550 board', 'socket' => 'AM4', 'specs' => ['socket' => 'AM4']];

$wrongMem = $memRule->evaluate(['motherboard' => $am4Board, 'ram' => $ddr5]);
expect('memory_supported REJECTS DDR5 on an AM4 board', $wrongMem === false, 'got '.var_export($wrongMem, true));

$rightMem = $memRule->evaluate(['motherboard' => $am4Board, 'ram' => $ddr4]);
expect('memory_supported ACCEPTS DDR4 on an AM4 board', $rightMem === true, 'got '.var_export($rightMem, true));

// --- 5. A too-long GPU for the case --------------------------------------------------
// SHAPE NOTE: evaluateClearance reads specs.length and specs.max_gpu_length - NOT length_mm.
$clrRule = rule('clearance_check', 'clearance', 'Graphics card fits the case');
$longGpu = ['name' => 'Huge GPU', 'length' => 360, 'specs' => ['length' => 360]];
$smallCaseGpu = ['name' => 'Compact case', 'specs' => ['max_gpu_length' => 280, 'supported_form_factors' => ['mATX']]];

$tooLong = $clrRule->evaluate(['gpu' => $longGpu, 'case' => $smallCaseGpu]);
expect('clearance_check REJECTS a 360mm GPU in a 280mm case', $tooLong === false, 'got '.var_export($tooLong, true));

$okLen = $clrRule->evaluate(['gpu' => ['name' => 'Slim GPU', 'specs' => ['length' => 240]], 'case' => $smallCaseGpu]);
expect('clearance_check ACCEPTS a 240mm GPU in that case', $okLen === true, 'got '.var_export($okLen, true));

// --- 6. THE REAL FINDING: these rules FAIL OPEN when catalogue data is missing ---------
echo "\n  ...and now the part that actually matters - missing catalogue data:\n";

$missingLen = $clrRule->evaluate([
    'gpu' => ['name' => 'GPU with no recorded length', 'specs' => []],
    'case' => ['name' => 'Case with no recorded limit', 'specs' => []],
]);
expect('FAIL-OPEN: clearance_check reports PASS when NEITHER length is known', $missingLen === true, 'got '.var_export($missingLen, true).' - this is the vacuous pass');

$noSpeed = $memRule->evaluate(['motherboard' => $am4Board, 'ram' => ['name' => 'Mystery RAM', 'specs' => []]]);
expect('FAIL-OPEN: memory_supported reports PASS when the memory speed is unknown', $noSpeed === true, 'got '.var_export($noSpeed, true));

echo "\n  Those two are EXPECTED to pass - the rules are written not to block a half-finished\n";
echo "  build. But a customer reading a green panel cannot tell 'verified' from 'no data'.\n";

echo "\n$pass passed, $fail failed\n";
echo "\nIf a rule cannot go RED on a deliberately broken build, seeding it adds a line to the\n";
echo "database and no protection. Those are the ones to leave unseeded.\n";

exit($fail === 0 ? 0 : 1);
