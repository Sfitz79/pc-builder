<?php
/** READ-ONLY. Ryzen series + G-variant coverage, and what compatibility machinery exists. */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\CatalogueGate;

$all = Component::query()->with('category')->active()->get();

echo "########## Ryzen CPUs: series x G-variant, active ##########" . PHP_EOL;
$rows = [];
foreach ($all as $c) {
    if ($c->category?->slug !== 'cpu') continue;
    $n = strtoupper((string) $c->name);
    if (! preg_match('/RYZEN\s+([3579])\s*(\d{4})(G?)/', $n, $m)) continue;
    $num = (int) $m[2];
    $band = $num >= 9000 ? '9000' : ($num >= 8000 ? '8000' : ($num >= 7000 ? '7000' : ($num >= 5000 ? '5000' : 'older')));
    $rows[$band][$m[3] === 'G' ? 'G-variant' : 'non-G'] = ($rows[$band][$m[3] === 'G' ? 'G-variant' : 'non-G'] ?? 0) + 1;
}
ksort($rows);
foreach ($rows as $band => $kinds) {
    echo '  ' . str_pad($band, 8);
    foreach ($kinds as $k => $v) echo str_pad($k . '=' . $v, 16);
    echo PHP_EOL;
}

echo PHP_EOL . "########## Ryzen 8000 / G names present ##########" . PHP_EOL;
$n = 0;
foreach ($all as $c) {
    if ($c->category?->slug !== 'cpu') continue;
    $nm = strtoupper((string) $c->name);
    if (preg_match('/RYZEN\s+\d\s+(8\d{3}|9\d{3}|7\d{3})G/', $nm)) {
        echo '  ' . str_pad(mb_substr((string) $c->name, 0, 34), 36) . 'socket=' . CatalogueGate::cpuSocket((string) $c->name)
            . '  series=' . CatalogueGate::cpuSeries((string) $c->name, CatalogueGate::cpuSocket((string) $c->name)) . PHP_EOL;
        if (++$n >= 12) break;
    }
}
echo '  total G-variant rows shown: ' . $n . PHP_EOL;

echo PHP_EOL . "########## compatibility machinery ##########" . PHP_EOL;
echo 'CompatibilityRule rows: ' . \App\Models\CompatibilityRule::count() . PHP_EOL;
foreach (\App\Models\CompatibilityRule::limit(12)->get() as $r) {
    echo '  ' . str_pad((string) ($r->component_a_type ?? '?'), 14) . str_pad((string) ($r->relation ?? '?'), 16)
        . ' -> ' . str_pad((string) ($r->component_b_type ?? '?'), 14) . ' ok=' . var_export($r->is_valid ?? null, true) . PHP_EOL;
}

echo PHP_EOL . "########## services / validation on the builder ##########" . PHP_EOL;
foreach (glob(app_path('Services/*.php')) as $f) echo '  ' . basename($f) . PHP_EOL;

echo PHP_EOL . "########## /builder/validate route ##########" . PHP_EOL;
$ctrl = new ReflectionClass(\App\Http\Controllers\BuilderController::class);
foreach ($ctrl->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    if (preg_match('/valid|compat|check/i', $m->getName())) echo '  method: ' . $m->getName() . PHP_EOL;
}