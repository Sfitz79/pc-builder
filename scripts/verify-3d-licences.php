<?php

/**
 * Checks the 3D asset manifest against the licence gate.
 *
 * Separate from verify-3d-licence-gate.php, which proves the POLICY. This proves
 * the MANIFEST: that every asset recorded has been judged, and that nothing
 * rejected or awaiting review is being treated as cleared.
 *
 *   php scripts/verify-3d-licences.php
 *
 * An empty manifest passes with a clear note, because no assets have been sampled
 * yet. It does NOT silently pass if an asset exists but has no licence field.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ThreeD\AssetLicenceGate;

$path = __DIR__ . '/../resources/3d/asset-licences.json';

if (!is_file($path)) {
    fwrite(STDERR, "FAIL manifest not found at resources/3d/asset-licences.json\n");
    exit(1);
}

$json = json_decode((string) file_get_contents($path), true);
if (!is_array($json) || !isset($json['assets']) || !is_array($json['assets'])) {
    fwrite(STDERR, "FAIL manifest is not valid JSON with an 'assets' array\n");
    exit(1);
}

$assets = $json['assets'];

echo "=== 3D asset licence manifest ===\n";
echo "  file    : resources/3d/asset-licences.json\n";
echo "  assets  : " . count($assets) . "\n\n";

if ($assets === []) {
    echo "No third-party assets sampled yet - nothing to clear.\n\n";
    echo "When one is sampled, record it with the EXACT licence string from the asset\n";
    echo "page. Do not paraphrase it, and do not infer it from neighbouring uploads:\n";
    echo "these platforms mix CC0, CC-BY, CC-BY-NC and editorial in one search result.\n\n";
    echo "Then run: php scripts/verify-3d-licence-gate.php   (policy, 26 checks)\n";
    echo "      and: php scripts/verify-3d-licences.php      (this manifest)\n";
    echo "\nPASS: manifest is valid and empty.\n";
    exit(0);
}

$result = AssetLicenceGate::evaluateManifest($assets);
$counts = $result['counts'];

printf(
    "  allowed=%d  attribution-required=%d  review-required=%d  rejected=%d\n\n",
    $counts[AssetLicenceGate::ALLOWED],
    $counts[AssetLicenceGate::ATTRIBUTION_REQUIRED],
    $counts[AssetLicenceGate::REVIEW],
    $counts[AssetLicenceGate::REJECTED]
);

foreach ($result['rows'] as $row) {
    printf("  [%-19s] %s\n", $row['verdict'], $row['name']);
    printf("      licence: %s%s\n", $row['licence'], $row['source'] !== '' ? '  (' . $row['source'] . ')' : '');
    printf("      %s\n", $row['reason']);
    if ($row['attribution'] !== '') {
        printf("      credit: %s\n", $row['attribution']);
    }
}

$blocked = $counts[AssetLicenceGate::REJECTED] + $counts[AssetLicenceGate::REVIEW];

echo "\n";
if ($counts[AssetLicenceGate::REJECTED] > 0) {
    printf("FAIL: %d rejected asset(s). These must not be used or shipped.\n", $counts[AssetLicenceGate::REJECTED]);
    echo "See docs/3d-asset-licences.md.\n";
    exit(1);
}
if ($counts[AssetLicenceGate::REVIEW] > 0) {
    printf("FAIL: %d asset(s) awaiting a human read of their platform terms.\n", $counts[AssetLicenceGate::REVIEW]);
    echo "Marketplace licences differ per platform and change. Read each one.\n";
    exit(1);
}

echo "PASS: all recorded assets clear the gate.\n";
if ($counts[AssetLicenceGate::ATTRIBUTION_REQUIRED] > 0) {
    printf(
        "REMINDER: %d CC-BY asset(s) require a visible credit wherever the render is used.\n",
        $counts[AssetLicenceGate::ATTRIBUTION_REQUIRED]
    );
}
echo "Meshes stay in-house. Customers receive a rendered image of their own build.\n";
