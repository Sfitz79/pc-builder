<?php

/**
 * Post-deploy proof that the modern gate is live on the storefront.
 *
 * Local proof is not enough (see the 2026-10-04 outage: the gate verified
 * clean against Neon and still 500'd in production because the class was never
 * imported). This script compares what Neon actually holds against what the
 * PUBLIC endpoint actually serves, per category. If the two disagree the gate
 * is not really applied and we say so.
 *
 * Usage: php scripts/verify-live-gate.php [https://pctechguy.app]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\CatalogueGate;

$base = rtrim($argv[1] ?? 'https://pctechguy.app', '/');

echo "Verifying the live catalogue gate against Neon\n";
echo "  endpoint: {$base}/builder/catalog\n\n";

// --- what the database holds, after the gate ---
$dbGated = Component::query()
    ->with('category', 'manufacturer')
    ->active()
    ->get()
    ->reject(fn (Component $c) => CatalogueGate::rejectReason($c) !== null)
    ->groupBy(fn (Component $c) => $c->category?->slug ?? 'misc')
    ->map(fn ($g) => $g->count())
    ->all();

// --- what customers are actually served ---
$ctx = stream_context_create(['http' => ['timeout' => 90, 'ignore_errors' => true]]);
$body = @file_get_contents("{$base}/builder/catalog", false, $ctx);

if ($body === false) {
    fwrite(STDERR, "FAIL: could not reach the live endpoint.\n");
    exit(1);
}

$live = json_decode($body, true);

if (!is_array($live)) {
    fwrite(STDERR, "FAIL: endpoint did not return a JSON object (got "
        . strlen($body) . " bytes starting: "
        . substr((string) $body, 0, 120) . ")\n");
    exit(1);
}

$liveCount = [];
foreach ($live as $slug => $rows) {
    $liveCount[$slug] = count((array) $rows);
}

$slugs = array_unique(array_merge(array_keys($dbGated), array_keys($liveCount)));
sort($slugs);

printf("%-14s %10s %10s %8s\n", 'CATEGORY', 'NEON', 'LIVE', 'MATCH');
printf("%-14s %10s %10s %8s\n", str_repeat('-', 14), str_repeat('-', 10), str_repeat('-', 10), '------');

$bad = [];
foreach ($slugs as $slug) {
    $db = (int) ($dbGated[$slug] ?? 0);
    $lv = (int) ($liveCount[$slug] ?? 0);
    $ok = $db === $lv;
    if (!$ok) {
        $bad[] = $slug;
    }
    printf("%-14s %10d %10d %8s\n", $slug, $db, $lv, $ok ? 'ok' : 'MISMATCH');
}

$totalDb = array_sum($dbGated);
$totalLive = array_sum($liveCount);

echo "\ntotal gated: {$totalDb} in Neon, {$totalLive} served\n";

// --- independent check: restricted platforms must not be publicly visible ---
$leaks = [];
foreach (($live['cpu'] ?? []) as $c) {
    if (preg_match('/Ryzen\s+(1\d{3}|[2-4]\d{3})\b/i', (string) $c['name'])) {
        $leaks[] = 'cpu: ' . $c['name'];
    }
}
foreach (($live['psu'] ?? []) as $c) {
    if (isset($c['wattage']) && (int) $c['wattage'] < 650) {
        $leaks[] = 'psu under 650W: ' . $c['name'];
    }
}
$entryChipsets = ['A520', 'A620', 'B450', 'H510', 'H610', 'Z390', 'Z490', 'B365', 'H370'];
foreach (($live['motherboard'] ?? []) as $c) {
    $cs = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($c['chipset'] ?? '')));
    foreach ($entryChipsets as $entry) {
        if ($cs === $entry) {
            $leaks[] = 'entry chipset: ' . $c['name'] . ' [' . $c['chipset'] . ']';
        }
    }
}

echo "\n=== restricted platforms visible to customers ===\n";
if ($leaks === []) {
    echo "  none - Ryzen 1000-4000, entry chipsets and sub-650W PSUs are all hidden\n";
} else {
    foreach (array_slice($leaks, 0, 15) as $l) {
        echo "  LEAK {$l}\n";
    }
    echo '  ' . count($leaks) . " leak(s)\n";
}

if ($bad !== [] || $leaks !== []) {
    fwrite(STDERR, "\nGATE NOT CORRECTLY APPLIED - mismatched: " . implode(', ', $bad) . "\n");
    exit(1);
}

echo "\nPASS: the live catalogue matches Neon exactly and hides every restricted platform.\n";