<?php
/** READ-ONLY. Which rows does the gate hide as "unidentifiable", and should it? */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\CatalogueGate;

$all = Component::query()->with('category')->active()->get();

foreach (['motherboard', 'cpu'] as $slug) {
    echo "########## $slug hidden as UNIDENTIFIABLE ##########" . PHP_EOL;
    $n = 0;
    foreach ($all as $c) {
        if ($c->category?->slug !== $slug) continue;
        $why = CatalogueGate::rejectReason($c);
        if ($why === null || str_contains((string) $why, 'could not be identified')) continue;
        echo '  ' . mb_substr((string) $c->name, 0, 60) . PHP_EOL;
        $n++;
    }
    echo "  total: $n" . PHP_EOL . PHP_EOL;
}

echo "########## chipset derivation on a sample of kept boards ##########" . PHP_EOL;
$n = 0;
foreach ($all as $c) {
    if ($c->category?->slug !== 'motherboard') continue;
    if (CatalogueGate::rejectReason($c) !== null) continue;
    echo '  ' . str_pad(CatalogueGate::chipset((string) $c->name), 10)
        . str_pad(CatalogueGate::boardSocket((string) $c->name), 12)
        . mb_substr((string) $c->name, 0, 46) . PHP_EOL;
    if (++$n >= 15) break;
}

echo PHP_EOL . "########## grouped CPU keys ##########" . PHP_EOL;
$g = [];
foreach ($all as $c) {
    if ($c->category?->slug !== 'cpu') continue;
    if (CatalogueGate::rejectReason($c) !== null) continue;
    $k = CatalogueGate::groupFor($c)['key'];
    $g[$k] = ($g[$k] ?? 0) + 1;
}
ksort($g, SORT_NATURAL);
foreach ($g as $k => $v) echo '  ' . str_pad((string) $k, 36) . $v . PHP_EOL;

echo PHP_EOL . "########## grouped motherboard keys ##########" . PHP_EOL;
$g = [];
foreach ($all as $c) {
    if ($c->category?->slug !== 'motherboard') continue;
    if (CatalogueGate::rejectReason($c) !== null) continue;
    $k = CatalogueGate::groupFor($c)['key'];
    $g[$k] = ($g[$k] ?? 0) + 1;
}
ksort($g, SORT_NATURAL);
foreach ($g as $k => $v) echo '  ' . str_pad((string) $k, 20) . $v . PHP_EOL;

echo PHP_EOL . "########## grouped ram / storage keys ##########" . PHP_EOL;
foreach (['ram', 'storage'] as $slug) {
    $g = [];
    foreach ($all as $c) {
        if ($c->category?->slug !== $slug) continue;
        if (CatalogueGate::rejectReason($c) !== null) continue;
        $k = CatalogueGate::groupFor($c)['key'];
        $g[$k] = ($g[$k] ?? 0) + 1;
    }
    ksort($g, SORT_NATURAL);
    echo '  ' . strtoupper($slug) . ':' . PHP_EOL;
    foreach ($g as $k => $v) echo '    ' . str_pad((string) $k, 22) . $v . PHP_EOL;
}