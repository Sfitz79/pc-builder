<?php

/**
 * Licence gate proof.
 *
 * Verifies App\Services\ThreeD\AssetLicenceGate against the licence strings these
 * platforms actually hand out, including the combinations that are easy to get
 * wrong. Run:
 *
 *   php scripts/verify-3d-licence-gate.php
 *
 * The gate FAILS CLOSED, so this also proves that absent and nonsense licences
 * are rejected rather than waved through.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ThreeD\AssetLicenceGate;

$pass = 0;
$fail = 0;

function check(string $label, string $licence, string $expectedVerdict): void
{
    global $pass, $fail;

    $r = AssetLicenceGate::evaluate($licence);
    if ($r['verdict'] === $expectedVerdict) {
        $pass++;
        printf("  ok   %-58s -> %s\n", $label, $r['verdict']);
        return;
    }
    $fail++;
    printf("  FAIL %-58s -> %s (expected %s)\n         %s\n", $label, $r['verdict'], $expectedVerdict, $r['reason']);
}

echo "=== fail-closed behaviour ===\n";
check('empty string', '', AssetLicenceGate::REJECTED);
check('"unknown"', 'unknown', AssetLicenceGate::REJECTED);
check('"none"', 'none', AssetLicenceGate::REJECTED);
check('"n/a"', 'n/a', AssetLicenceGate::REJECTED);
check('gibberish', 'free to use pretty much', AssetLicenceGate::REJECTED);

echo "\n=== public domain ===\n";
check('CC0', 'CC0', AssetLicenceGate::ALLOWED);
check('CC0-1.0', 'CC0-1.0', AssetLicenceGate::ALLOWED);
check('Public Domain', 'Public Domain', AssetLicenceGate::ALLOWED);

echo "\n=== CC-BY: usable, attribution owed ===\n";
check('CC-BY-4.0', 'CC-BY-4.0', AssetLicenceGate::ATTRIBUTION_REQUIRED);
check('cc-by-3.0', 'cc-by-3.0', AssetLicenceGate::ATTRIBUTION_REQUIRED);
check('"Attribution"', 'Attribution', AssetLicenceGate::ATTRIBUTION_REQUIRED);

echo "\n=== NC is fatal even when buried in a CC-BY string ===\n";
check('CC-BY-NC-4.0', 'CC-BY-NC-4.0', AssetLicenceGate::REJECTED);
check('CC-BY-NC-SA-4.0', 'CC-BY-NC-SA-4.0', AssetLicenceGate::REJECTED);
check('"NonCommercial"', 'NonCommercial', AssetLicenceGate::REJECTED);

echo "\n=== ND is fatal: recombining is still a derivative ===\n";
check('CC-BY-ND-4.0', 'CC-BY-ND-4.0', AssetLicenceGate::REJECTED);
check('CC-BY-NC-ND-4.0', 'CC-BY-NC-ND-4.0', AssetLicenceGate::REJECTED);
check('"NoDerivatives"', 'NoDerivatives', AssetLicenceGate::REJECTED);

echo "\n=== share-alike reaches our output ===\n";
check('CC-BY-SA-4.0', 'CC-BY-SA-4.0', AssetLicenceGate::REJECTED);
check('"ShareAlike"', 'ShareAlike', AssetLicenceGate::REJECTED);

echo "\n=== editorial and personal-use ===\n";
check('Editorial', 'Editorial', AssetLicenceGate::REJECTED);
check('Editorial Use Only', 'Editorial Use Only', AssetLicenceGate::REJECTED);
check('Personal Use', 'Personal Use', AssetLicenceGate::REJECTED);

echo "\n=== marketplace licences need a human read ===\n";
check('Royalty Free', 'Royalty Free', AssetLicenceGate::REVIEW);
check('"Standard Licence"', 'Standard Licence', AssetLicenceGate::REVIEW);

echo "\n=== manifest: CC-BY with no author recorded is NOT usable ===\n";
$m = AssetLicenceGate::evaluateManifest([
    ['name' => 'GPU shroud', 'licence' => 'CC0', 'source' => 'OpenGameArt'],
    ['name' => 'Motherboard', 'licence' => 'CC-BY-4.0', 'source' => 'Sketchfab', 'attribution' => 'Some Creator'],
    ['name' => 'Case shell', 'licence' => 'CC-BY-4.0', 'source' => 'Sketchfab'],
    ['name' => 'Fan', 'licence' => 'CC-BY-NC', 'source' => 'CGTrader'],
]);
$c = $m['counts'];
if ($c[AssetLicenceGate::ALLOWED] === 1
    && $c[AssetLicenceGate::ATTRIBUTION_REQUIRED] === 1
    && $c[AssetLicenceGate::REJECTED] === 2) {
    $pass++;
    echo "  ok   manifest counts: 1 CC0 allowed, 1 CC-BY credited, 2 rejected (one NC, one uncredited CC-BY)\n";
} else {
    $fail++;
    echo "  FAIL manifest counts wrong: " . json_encode($c) . "\n";
}
if ($m['pass'] === false) {
    $pass++;
    echo "  ok   manifest containing rejected assets reports pass=false\n";
} else {
    $fail++;
    echo "  FAIL manifest with rejected assets reported pass=true\n";
}

printf("\n%s: %d checks.\n", $fail === 0 ? 'PASS' : 'FAIL', $pass + $fail);

if ($fail > 0) {
    echo "Some licence decisions are wrong. Do not ship third-party assets until this passes.\n";
    exit(1);
}

echo "Only CC0 and credited CC-BY clear this gate. Meshes stay in-house; customers\n";
echo "receive a rendered image of their own build, never a source file.\n";
