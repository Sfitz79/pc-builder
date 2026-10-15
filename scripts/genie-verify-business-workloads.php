<?php

/**
 * READ-ONLY. Runs the real business workloads against the REAL production
 * catalogue and prints what each one actually buys.
 *
 * The point: a synthetic fixture can prove that the shares are computed and that
 * they change the pick. It cannot prove that the pick is a sensible machine a
 * business buyer would accept. This does.
 *
 * Writes nothing.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "db driver : " . config('database.default') . " (" . DB::connection()->getDriverName() . ")\n";
echo "active    : " . \App\Models\Component::query()->active()->count() . " components\n";
echo "gemini    : " . (app(\App\Services\GeminiService::class)->available() ? 'CONFIGURED' : 'not configured') . "\n\n";

$service = app(\App\Services\AIRecommendationService::class);

$shares = new ReflectionMethod($service, 'workloadShares');
$shares->setAccessible(true);

$quote = function (string $purpose, float $budget) use ($service) {
    $r = $service->recommendBoth($budget, $purpose, null);
    $b = $r['budget'];
    $rows = [];
    foreach (($b['components'] ?? []) as $cat => $item) {
        $rows[$cat] = ($item['name'] ?? '?').'  £'.number_format((float) ($item['price'] ?? 0), 2);
    }

    return ['total' => $b['total'] ?? 0, 'rows' => $rows, 'complete' => $b['complete'] ?? false];
};

foreach ([2500.0, 4500.0] as $budget) {
    echo str_repeat('=', 78)."\n";
    echo "  BUDGET £".number_format($budget, 0)."\n";
    echo str_repeat('=', 78)."\n";

    foreach (['gaming', 'studio', 'rendering', 'enterprise', 'server', 'nas', 'home-business', 'ai'] as $p) {
        $s = $shares->invoke($service, $p);
        $q = $quote($p, $budget);

        printf(
            "\n%-14s shares=%-10s total=£%-10s complete=%s\n",
            $p,
            $s === null ? 'CALIBRATED' : 'declared',
            number_format($q['total'], 2),
            $q['complete'] ? 'yes' : 'NO'
        );

        foreach ($q['rows'] as $cat => $line) {
            printf("    %-12s %s\n", $cat, $line);
        }
    }
}

echo "\nNO WRITES PERFORMED - read-only.\n";
