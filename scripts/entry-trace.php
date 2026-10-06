<?php
/** READ-ONLY. Why do A520/A620/B450 survive the entry-chipset gate? */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\CatalogueGate;

echo "ENTRY_CHIPSETS contains: " . implode(', ', CatalogueGate::ENTRY_CHIPSETS) . PHP_EOL . PHP_EOL;

$boards = Component::query()->with('category')->active()->get()
    ->filter(fn ($c) => $c->category?->slug === 'motherboard');

echo "########## boards whose chipset lands on an entry part ##########" . PHP_EOL;
$n = 0;
foreach ($boards as $c) {
    $cs = CatalogueGate::chipset((string) $c->name);
    if (! in_array($cs, CatalogueGate::ENTRY_CHIPSETS, true)) continue;
    echo '  ' . str_pad($cs, 8) . 'entry=' . var_export(CatalogueGate::isEntryChipset((string) $c->name), true)
        . ' reason=' . var_export(CatalogueGate::rejectReason($c), true)
        . '  ' . mb_substr((string) $c->name, 0, 42) . PHP_EOL;
    if (++$n >= 12) break;
}
echo '  shown: ' . $n . PHP_EOL . PHP_EOL;

echo "########## A520/A620/B450 rows: full trace ##########" . PHP_EOL;
$n = 0;
foreach ($boards as $c) {
    $cs = CatalogueGate::chipset((string) $c->name);
    if (! str_starts_with($cs, 'A5') && ! str_starts_with($cs, 'A6') && ! str_starts_with($cs, 'B4')) continue;
    echo '  name=' . str_pad(mb_substr((string) $c->name, 0, 40), 42)
        . ' chipset=' . str_pad($cs, 8)
        . ' isEntry=' . var_export(CatalogueGate::isEntryChipset((string) $c->name), true) . PHP_EOL;
    if (++$n >= 10) break;
}
echo '  shown: ' . $n . PHP_EOL;