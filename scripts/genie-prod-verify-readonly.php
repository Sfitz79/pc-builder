<?php
// Genie 2026-09-28: read-only production verification against Neon.
//
// This is the check that matters. Every previous run of the price-integrity
// and band logic was against local SQLite, which stores $table->boolean() as
// tinyint(1). Production stores it as a real bool, and an integer comparison
// throws SQLSTATE 42883 there. So the code is exercised here against the live
// 2,708-row catalogue, with no writes: only read-only service methods are
// called (freshness, untrustedCohorts, clean, workableBands, bandEntryPrice).
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\AIRecommendationService;
use App\Services\PriceIntegrityService;

$integrity = app(PriceIntegrityService::class);
$bands = app(AIRecommendationService::class);

echo '=== READ-ONLY production verification ==='.PHP_EOL;
printf('db driver: %s'.PHP_EOL, Illuminate\Support\Facades\DB::connection()->getDriverName());

// 1. The exact query that threw 42883 before the boolean fix.
$active = Component::where('active', true)->count();
printf('where(active, true) count : %d  <- threw 42883 before the fix'.PHP_EOL, $active);

$pool = Component::where('active', true)->get();
printf('loaded pool              : %d components'.PHP_EOL, $pool->count());

// 2. price_checked_at now exists, so freshness can actually be evaluated.
$withChecked = $pool->filter(fn (Component $c) => $c->price_checked_at !== null)->count();
printf('pool with price_checked_at: %d'.PHP_EOL, $withChecked);

$sample = $pool->firstWhere('price_checked_at', '!=', null) ?? $pool->first();
$fresh = $integrity->freshness($sample);
printf('freshness() on [%d]      : %s'.PHP_EOL, $sample->id, json_encode($fresh));

// 3. buildFreshness + clean over the whole live catalogue.
$fr = $integrity->buildFreshness($pool->toArray());
printf('buildFreshness() top keys : %s'.PHP_EOL, json_encode(array_keys($fr)));
printf('buildFreshness() summary  : %s'.PHP_EOL, json_encode(array_map(
    fn ($v) => is_array($v) ? count($v) : $v,
    $fr
)));
$clean = $integrity->clean($pool);
printf('clean() publishable      : %d of %d'.PHP_EOL, $clean->count(), $pool->count());

// 4. Cohorts - the whole point of the evidence gate.
$cohorts = $integrity->untrustedCohorts();
printf('untrustedCohorts()       : %s'.PHP_EOL, json_encode(array_map(
    fn (string $k, $v) => $k.'='.(is_array($v) ? count($v) : $v),
    array_keys($cohorts),
    $cohorts
)));

// 5. The band floors, measured against the real catalogue rather than SQLite.
//
// workableBands() returns min/max/label/floor_gpu_tier/measured. `measured`
// is the honest signal: when it is false, `min` is the RESOLUTION_BANDS
// hand-typed fallback rather than a real measurement, so the published floor
// is a guess and must not be described as measured.
$wb = $bands->workableBands();
printf(PHP_EOL.'workableBands() on production:'.PHP_EOL);
foreach ($wb as $key => $band) {
    printf(
        '  %-8s min=GBP %8.2f  max=GBP %8.2f  gpu_tier=%d  measured=%s%s'.PHP_EOL,
        $key,
        $band['min'],
        $band['max'],
        $band['floor_gpu_tier'],
        $band['measured'] ? 'YES' : 'no (FALLBACK)',
        $band['measured'] ? '' : '  <- hand-typed default'
    );
}
foreach (['1080p', '1440p', '4K', '4k'] as $res) {
    $e = $bands->bandEntryPrice($res);
    if ($e !== null) {
        printf('  bandEntryPrice(%-5s) = GBP %.2f'.PHP_EOL, $res, $e);
    }
}

echo PHP_EOL.'NO WRITES PERFORMED - read-only verification only.'.PHP_EOL;
