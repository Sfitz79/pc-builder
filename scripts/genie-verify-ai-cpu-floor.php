<?php

/**
 * PROOF that the AI recommendation path can no longer quote a pre-Zen3 Ryzen.
 *
 * The fix was one added condition in AIRecommendationService::viablePool('cpu', ...). This
 * exercises THAT method - not the gate in isolation - against the real production catalogue,
 * because the whole failure was that a correct gate existed on three other paths and was
 * simply absent from this one. Proving the gate works again proves nothing about this path.
 *
 * viablePool() is protected, so it is called by reflection. Read-only: nothing is written.
 *
 * Usage: php scripts/genie-verify-ai-cpu-floor.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Component;
use App\Services\AIRecommendationService;
use Illuminate\Support\Facades\DB;

$service = app(AIRecommendationService::class);

$pool = Component::query()
    ->where('components.active', true)
    ->whereIn('components.category_id', Category::where('slug', 'cpu')->pluck('id'))
    ->get();

echo 'RAW ACTIVE CPU POOL: '.$pool->count()."\n";

$method = new ReflectionMethod(AIRecommendationService::class, 'viablePool');
$method->setAccessible(true);

/** @var \Illuminate\Support\Collection $viable */
$viable = $method->invoke($service, 'cpu', $pool);

echo 'AFTER viablePool(\'cpu\', ...): '.$viable->count()."  (the pool the AI path picks from)\n\n";

function isPreZen3Ryzen(string $name): bool
{
    return preg_match('/ryzen\s+\d\s+(\d{4})(?!\d)/i', $name, $m) === 1
        && (int) $m[1] < 5000;
}

$leaks = $viable->filter(fn ($c) => isPreZen3Ryzen((string) $c->name));

echo 'PRE-ZEN3 RYZEN STILL IN THE AI POOL: '.$leaks->count()."\n";
if ($leaks->isNotEmpty()) {
    echo "  *** LEAK - these would be quoted to customers:\n";
    foreach ($leaks as $c) {
        echo '    '.$c->id.'  '.$c->name."\n";
    }
    exit(1);
}
echo "  none. The leak is closed.\n";

// The cheapest surviving CPU is what ->sortBy('price') would actually hand a customer,
// so naming it proves the fix changed the outcome rather than just the count.
$cheapest = $viable->sortBy('price')->first();
echo "\nCHEAPEST CPU THE AI PATH CAN NOW QUOTE\n";
if ($cheapest) {
    echo '  '.$cheapest->name."   (GBP ".(float) $cheapest->price.")\n";
    echo '  -> previously this slot went to a Ryzen 5 3600 / Ryzen 3 3200G.\n';
    echo '  is pre-Zen3: '.(isPreZen3Ryzen((string) $cheapest->name) ? 'YES (FAIL)' : 'no')."\n";
}

// Regression guard: the floor must not have emptied the pool or killed modern parts.
$modern = $viable->filter(function ($c) {
    return preg_match('/ryzen\s+\d\s+(\d{4})(?!\d)/i', (string) $c->name, $m) && (int) $m[1] >= 5000;
});

echo "\nREGRESSION CHECKS\n";
echo '  pool not empty            : '.($viable->isNotEmpty() ? 'PASS' : 'FAIL - no CPU can be quoted at all')."\n";
echo '  Zen3+ Ryzen still present : '.$modern->count()."\n";

$sanity = $viable->filter(fn ($c) => stripos((string) $c->name, '7800X3D') !== false
    || stripos((string) $c->name, '9800X3D') !== false
    || stripos((string) $c->name, '5600X') !== false);
echo '  X3D / 5600X still allowed : '.$sanity->count().' '.$sanity->pluck('name')->implode(', ')."\n";
echo '    (a generation floor that also removed modern parts would be a broken fix)\n';
