<?php
/**
 * READ-ONLY dry run of CatalogueGate against the live catalogue.
 * Writes nothing. Prints exactly what would be hidden and why, and what each
 * selection list would group into - so the Boss can read the effect before any
 * of it is wired into the storefront.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\CatalogueGate;

$all = Component::query()->with('category', 'manufacturer')->active()->get();

echo 'active components: ' . $all->count() . PHP_EOL . PHP_EOL;

$audit = CatalogueGate::audit($all);
echo 'would KEEP : ' . $audit['kept'] . PHP_EOL;
$totalHidden = array_sum(array_map('array_sum', $audit['hidden']));
echo 'would HIDE : ' . $totalHidden . PHP_EOL . PHP_EOL;

echo '########## what would be hidden, by reason ##########' . PHP_EOL;
foreach ($audit['hidden'] as $slug => $reasons) {
    echo PHP_EOL . '### ' . $slug . PHP_EOL;
    arsort($reasons);
    foreach ($reasons as $why => $n) echo '  ' . str_pad((string) $n, 5) . $why . PHP_EOL;
}

echo PHP_EOL . '########## survival per category ##########' . PHP_EOL;
foreach (['cpu', 'motherboard', 'ram', 'storage', 'psu', 'gpu', 'case', 'cooler'] as $slug) {
    $inCat = $all->filter(fn ($c) => $c->category?->slug === $slug);
    $kept = $inCat->filter(fn ($c) => CatalogueGate::rejectReason($c) === null)->count();
    printf("  %-12s %4d active  ->  %4d kept  (%d hidden)%s", $slug, $inCat->count(), $kept,
        $inCat->count() - $kept, PHP_EOL);
    if ($kept === 0) echo "      *** CATEGORY WOULD BE EMPTY - DO NOT SHIP ***" . PHP_EOL;
}

echo PHP_EOL . '########## how each selection list would group ##########' . PHP_EOL;
foreach (['cpu', 'motherboard', 'ram', 'storage'] as $slug) {
    echo PHP_EOL . '### ' . strtoupper($slug) . PHP_EOL;
    $groups = [];
    foreach ($all as $c) {
        if ($c->category?->slug !== $slug) continue;
        if (CatalogueGate::rejectReason($c) !== null) continue;
        $g = CatalogueGate::groupFor($c);
        $groups[$g['key']] = ($groups[$g['key']] ?? 0) + 1;
    }
    if (count($groups)) ksort($groups, SORT_NATURAL);
    foreach ($groups as $k => $n) echo '  ' . str_pad((string) $k, 34) . $n . PHP_EOL;
}

echo PHP_EOL . '########## sample of CPUs that would be hidden ##########' . PHP_EOL;
$n = 0;
foreach ($all as $c) {
    if ($c->category?->slug !== 'cpu') continue;
    $why = CatalogueGate::rejectReason($c);
    if ($why === null) continue;
    echo '  ' . str_pad(mb_substr((string) $c->name, 0, 44), 46) . $why . PHP_EOL;
    if (++$n >= 20) break;
}

echo PHP_EOL . '########## sanity: any modern part wrongly hidden? ##########' . PHP_EOL;
$suspects = 0;
foreach ($all as $c) {
    $why = CatalogueGate::rejectReason($c);
    if ($why === null) continue;
    $n = strtoupper((string) $c->name);
    if (preg_match('/(RYZEN\s*(?:[579]\s*)?[579]000|CORE\s+(?:I[579]|ULTRA\s+[579]))/', $n)) {
        echo '  SUSPECT: ' . mb_substr((string) $c->name, 0, 50) . ' -> ' . $why . PHP_EOL;
        $suspects++;
    }
}
echo $suspects === 0 ? "  none - no Ryzen 5000+/7000/9000 or Core i5/i7/Ultra was hidden." . PHP_EOL
                     : "  $suspects suspect(s) above - investigate before shipping." . PHP_EOL;