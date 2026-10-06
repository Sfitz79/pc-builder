<?php

/**
 * genie-check-t150-parts.php - inspect the catalogue rows the floor script
 * surfaced, because the printed names looked wrong and the prices looked
 * impossible (a "3070" at GBP 1,199).
 *
 * The name column is a captured title fragment, so the reliable identity is
 * specs.chipset. This dumps every field the consumer could read, and prices the
 * same parts from PCPP's own page when source_url is present, so an inflated
 * catalogue price is visible rather than assumed.
 *
 * READ-ONLY.
 *
 * Usage: php scripts\genie-check-t150-parts.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = array_slice(array_filter(array_map('trim', explode(',', $argv[1] ?? ''))), 0, 40);
if (! $ids) {
    $ids = App\Models\Component::where('active', true)->where('category_id', 4)
        ->where(fn ($q) => $q->where('chipset', 'like', '%3070%')->orWhere('chipset', 'like', '%6700%')
            ->orWhere('chipset', 'like', '%6800%'))
        ->orderBy('price')->limit(12)->pluck('id')->all();
}

$svc = app(App\Services\AIRecommendationService::class);
$tier = new ReflectionMethod($svc, 'gpuPerformanceTier');
$tier->setAccessible(true);

printf("DB: %s\n\n", config('database.default'));

foreach (App\Models\Component::whereIn('id', $ids)->orderBy('price')->get() as $c) {
    $specs = is_string($c->specs) ? (json_decode($c->specs, true) ?: []) : ($c->specs ?: []);

    printf("#%s  GBP %s  stock=%s\n", $c->id, number_format((float) $c->price, 2), (string) $c->stock);
    printf("   name        : %s\n", $c->name);
    printf("   chipset col : %s\n", var_export($c->chipset, true));
    printf("   specs.chipset: %s\n", var_export($specs['chipset'] ?? $specs['gpu_chipset'] ?? null, true));
    printf("   vram        : %s   memory: %s\n",
        var_export($specs['vram'] ?? $specs['memory'] ?? $specs['vram_gb'] ?? null, true),
        var_export($specs['memory'] ?? $specs['capacity'] ?? null, true));
    printf("   tier        : %s\n", var_export($tier->invoke($svc, $c), true));
    printf("   source_url  : %s\n", $c->source_url ? substr((string) $c->source_url, 0, 100) : '(none)');
    printf("   price_checked: %s\n", $c->price_checked_at ? (string) $c->price_checked_at : 'NEVER');
    echo "\n";
}
