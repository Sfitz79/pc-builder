<?php
/**
 * Proves the gate end-to-end at the data level: same query + gate the endpoint
 * uses, then dumps every CPU name it would return.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\CatalogueGate;

$kept = Component::query()->with('category', 'manufacturer')->active()->get()
    ->reject(fn (Component $c) => CatalogueGate::rejectReason($c) !== null);

echo 'total returned by the endpoint: ' . $kept->count() . PHP_EOL . PHP_EOL;

foreach (['cpu', 'motherboard', 'ram', 'storage', 'psu'] as $slug) {
    $items = $kept->filter(fn ($c) => $c->category?->slug === $slug);
    echo '=== ' . strtoupper($slug) . ': ' . $items->count() . ' ===' . PHP_EOL;
    if ($slug === 'cpu') {
        $bySocket = $items->groupBy(fn ($c) => CatalogueGate::cpuSocket((string) $c->name));
        foreach ($bySocket as $socket => $group) {
            echo '  ' . $socket . ' (' . $group->count() . ')' . PHP_EOL;
            foreach ($group->map(fn ($c) => $c->name)->sort()->values() as $nm) {
                echo '     ' . $nm . PHP_EOL;
            }
        }
    } else {
        foreach ($items->take(4) as $c) echo '     ' . $c->name . PHP_EOL;
        if ($items->count() > 4) echo '     ... +' . ($items->count() - 4) . ' more' . PHP_EOL;
    }
    echo PHP_EOL;
}

echo '=== FINAL SANITY: is any excluded platform present? ===' . PHP_EOL;
$bad = $kept->filter(function ($c) {
    $slug = $c->category?->slug;
    $n = strtoupper((string) $c->name);
    if ($slug === 'cpu') {
        return preg_match('/RYZEN\s*(?:[3579]\s*)?[1234]\d{3}/', $n)
            || preg_match('/CORE\s+(?:I)?[3579]-\d{4}\b/', $n);
    }
    if ($slug === 'motherboard') {
        return (bool) preg_match('/(?<![A-Z0-9])(A[0-9]{3}|B450|H610|H670|H810|B660|Q570|Z490)(?![0-9])/', $n);
    }
    if ($slug === 'psu') return $c->wattage !== null && $c->wattage < 650;
    return false;
});
echo $bad->count() === 0
    ? '  CLEAN - no Ryzen 1000-4000, no 3rd/4th gen Intel, no entry chipset, no sub-650W PSU.' . PHP_EOL
    : '  STILL LEAKING ' . $bad->count() . ':' . PHP_EOL . $bad->take(10)->map(fn ($c) => '    ' . $c->name)->implode(PHP_EOL) . PHP_EOL;