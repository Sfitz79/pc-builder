<?php
// Genie 2026-09-28: prove the specs.chipset fix against the LIVE catalogue.
//
// Every previous band/tier verdict was reached against local SQLite. The
// production-only defects all came from that gap. This runs the real 306-row GPU
// catalogue through gpuPerformanceTier() and reports the distribution, plus the
// specific row that caused the live P1. Read-only.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();


require __DIR__ . '/genie-prod-guard.php';
use App\Models\Component;
use App\Services\AIRecommendationService;

$svc = app(AIRecommendationService::class);
$m = new ReflectionMethod(AIRecommendationService::class, 'gpuPerformanceTier');
$m->setAccessible(true);
$min = new ReflectionMethod(AIRecommendationService::class, 'minGpuTierFor');

echo '=== live GPU tier distribution (production) ==='.PHP_EOL;
printf('db driver: %s'.PHP_EOL, Illuminate\Support\Facades\DB::connection()->getDriverName());

$gpus = Component::where('category_id', 3)->where('active', true)->get();
$dist = [];
$unknown = [];
foreach ($gpus as $g) {
    $t = (int) $m->invoke($svc, $g);
    $dist[$t] = ($dist[$t] ?? 0) + 1;
    if ($t === 0) {
        $specs = (array) ($g->specs ?? []);
        $unknown[] = sprintf('#%d %s [specs.chipset=%s]', $g->id, mb_strimwidth((string) $g->name, 0, 30), $specs['chipset'] ?? 'none');
    }
}
ksort($dist);
$labels = [0 => 'UNCLASSIFIED (fails open - the defect)', 1 => 'esports/entry', 2 => '1080p', 3 => '1440p', 4 => 'strong 1440p / entry 4K', 5 => '4K'];
foreach ($dist as $t => $n) {
    printf('  tier %d  %-38s %4d'.PHP_EOL, $t, $labels[$t] ?? '', $n);
}
printf('  %-43s %4d'.PHP_EOL, 'TOTAL', $gpus->count());

echo PHP_EOL.'=== band floors ==='.PHP_EOL;
foreach (['1080p', '1440p', '4K'] as $r) {
    printf('  %-6s needs tier >= %d'.PHP_EOL, $r, (int) $min->invoke($svc, $r));
}

echo PHP_EOL.'=== the row that caused the live P1 ==='.PHP_EOL;
$eco = $gpus->first(fn ($g) => str_contains(strtoupper((string) $g->name), 'SPARKLE'));
if ($eco) {
    printf('  #%d %s  £%.2f'.PHP_EOL, $eco->id, $eco->name, $eco->price);
    printf('  specs: %s'.PHP_EOL, json_encode($eco->specs));
    $t = (int) $m->invoke($svc, $eco);
    $f = (int) $min->invoke($svc, '1080p');
    printf('  tier now: %d   1080p floor: %d   => %s'.PHP_EOL, $t, $f,
        $t >= $f ? 'ALLOWED' : 'REFUSED for 1080p (correct)');
} else {
    echo "  not found".PHP_EOL;
}

if ($unknown) {
    echo PHP_EOL.sprintf('=== still unclassified: %d ===', count($unknown)).PHP_EOL;
    foreach (array_slice($unknown, 0, 12) as $u) {
        echo "  $u".PHP_EOL;
    }
}
