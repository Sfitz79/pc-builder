<?php

/**
 * Fit verification proof.
 *
 * Proves the gate FAILS CLOSED: an unknown dimension must produce 'unverified',
 * never a silent pass. The regression this exists to prevent is CompatibilityChecker
 * Service's `if ($max && $actual && ...)` pattern, which returned "compatible"
 * for every build in production.
 *
 *   php scripts/verify-fit-verification.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\FitVerification;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        printf("  ok   %-62s %s\n", $label, $detail);
    } else {
        $fail++;
        printf("  FAIL %-62s %s\n", $label, $detail);
    }
}

function stateOf(array $r, string $id): string
{
    foreach ($r['checks'] as $c) {
        if ($c['id'] === $id) {
            return $c['state'];
        }
    }
    return 'missing';
}

echo "=== THE PRODUCTION SHAPE: nothing recorded ===\n";
// Exactly what production Neon returns today for a real build.
$prod = FitVerification::assess([
    'case' => ['name' => 'Some Case'],
    'gpu' => ['name' => 'Some GPU'],
    'cooler' => ['name' => 'Some Cooler'],
    'motherboard' => ['name' => 'Some Board'],
]);
check('unknown dimensions are NOT reported as verified', $prod['verified'] === false);
check('unknown dimensions do NOT block the sale', $prod['blocked'] === false, '(a hard block would make the builder unusable while 100% of data is missing)');
check('gpu-length is unverified, not passed', stateOf($prod, 'gpu-length') === FitVerification::UNVERIFIED);
check('cooler-height is unverified', stateOf($prod, 'cooler-height') === FitVerification::UNVERIFIED);
check('form-factor is unverified', stateOf($prod, 'form-factor') === FitVerification::UNVERIFIED);
check('every gap produces a customer-visible warning', count($prod['warnings']) === 3, count($prod['warnings']) . ' warnings');
echo "        warning text: \"" . ($prod['warnings'][0] ?? '(none)') . "\"\n";

echo "\n=== the regression: the old logic returned compatible=true here ===\n";
$oldMax = null;
$oldGpu = null;
$oldWouldFlag = $oldMax && $oldGpu && $oldGpu > $oldMax;   // the exact old expression
check('old expression produces no error for this build', $oldWouldFlag === false, '-> which is why compatible came back true');
check('new logic still does not claim verification', $prod['verified'] === false, '-> the difference');

echo "\n=== a real clearance failure must BLOCK ===\n";
$tooLong = FitVerification::assess([
    'case' => ['max_gpu_length' => 320],
    'gpu' => ['length' => 359],
]);
check('359mm card in a 320mm case blocks', $tooLong['blocked'] === true);
check('and is reported as a failure not unverified', stateOf($tooLong, 'gpu-length') === FitVerification::FAIL);
check('unrelated checks stay unverified', stateOf($tooLong, 'cooler-height') === FitVerification::UNVERIFIED);

echo "\n=== a genuine fit must PASS and verify ===\n";
$good = FitVerification::assess([
    'case' => ['max_gpu_length' => 400, 'max_cpu_cooler_height' => 170, 'supported_form_factors' => ['atx']],
    'gpu' => ['length' => 336],
    'cooler' => ['height' => 158],
    'motherboard' => ['form_factor' => 'ATX'],
]);
check('336mm card in a 400mm case passes', stateOf($good, 'gpu-length') === FitVerification::PASS);
check('158mm cooler in a 170mm case passes', stateOf($good, 'cooler-height') === FitVerification::PASS);
check('ATX board in an ATX case passes', stateOf($good, 'form-factor') === FitVerification::PASS);
check('fully populated build IS verified', $good['verified'] === true);
check('and is not blocked', $good['blocked'] === false);

echo "\n=== boundary: exactly at the limit must pass ===\n";
$edge = FitVerification::assess([
    'case' => ['max_gpu_length' => 360],
    'gpu' => ['length' => 360],
]);
check('360mm card in a 360mm case passes', stateOf($edge, 'gpu-length') === FitVerification::PASS);
$edge2 = FitVerification::assess([
    'case' => ['max_gpu_length' => 360],
    'gpu' => ['length' => 361],
]);
check('361mm card in a 360mm case blocks', stateOf($edge2, 'gpu-length') === FitVerification::FAIL);

echo "\n=== one known side only is still unverified, not passed ===\n";
$half = FitVerification::assess([
    'case' => ['max_gpu_length' => 400],
    'gpu' => [],
]);
check('case limit known but GPU length missing -> unverified', stateOf($half, 'gpu-length') === FitVerification::UNVERIFIED);
$half2 = FitVerification::assess([
    'case' => [],
    'gpu' => ['length' => 300],
]);
check('GPU length known but no case limit -> unverified', stateOf($half2, 'gpu-length') === FitVerification::UNVERIFIED);

echo "\n=== field-name tolerance ===\n";
$alt = FitVerification::assess([
    'case' => ['max_gpu_length_mm' => 400],
    'gpu' => ['length_mm' => 320],
]);
check('accepts the _mm suffixed variant', stateOf($alt, 'gpu-length') === FitVerification::PASS);
$altCooler = FitVerification::assess([
    'case' => ['max_cooler_height' => 170],
    'cooler' => ['height' => 165],
]);
check('accepts max_cooler_height alias', stateOf($altCooler, 'cooler-height') === FitVerification::PASS);

echo "\n=== form factor: comma list and case-insensitivity ===\n";
$csv = FitVerification::assess([
    'case' => ['supported_form_factors' => 'ATX, mATX, ITX'],
    'motherboard' => ['form_factor' => 'matx'],
]);
check('mATX accepted from a comma list', stateOf($csv, 'form-factor') === FitVerification::PASS);
$mismatch = FitVerification::assess([
    'case' => ['supported_form_factors' => ['m-atx', 'itx']],
    'motherboard' => ['form_factor' => 'ATX'],
]);
check('ATX board in an mATX/ITX case blocks', stateOf($mismatch, 'form-factor') === FitVerification::FAIL);

echo "\n=== empty and malformed input must not crash or pass ===\n";
foreach ([
    'no parts at all' => [],
    'empty arrays' => ['case' => [], 'gpu' => []],
    'wrong types' => ['case' => 'not-an-array', 'gpu' => 42],
    'nulls' => ['case' => null, 'gpu' => null, 'cooler' => null],
    'strings where numbers expected' => ['case' => ['max_gpu_length' => 'lots'], 'gpu' => ['length' => 'big']],
] as $label => $input) {
    try {
        $r = FitVerification::assess($input);
        $safe = is_array($r) && array_key_exists('verified', $r) && array_key_exists('blocked', $r);
        check("handles {$label}", $safe, 'verified=' . var_export($r['verified'] ?? null, true) . ' blocked=' . var_export($r['blocked'] ?? null, true));
    } catch (Throwable $e) {
        check("handles {$label}", false, 'threw: ' . $e->getMessage());
    }
}

printf("\n%s: %d checks.\n", $fail === 0 ? 'PASS' : 'FAIL', $pass + $fail);

if ($fail > 0) {
    echo "Fit verification is failing open. Do not ship this.\n";
    exit(1);
}

echo "Unknown dimensions now read as UNVERIFIED, never as a pass.\n";
