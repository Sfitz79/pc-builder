<?php
// Genie 2026-09-28: current state of the un-evidenced catalogue rows.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\PriceIntegrityService;

$integrity = app(PriceIntegrityService::class);
$quarantined = $integrity->quarantinedIds();

$active = Component::where('active', 1)->get();
$cats  = App\Models\Category::pluck('name', 'id');

$noSource = $active->filter(fn ($c) => trim((string) $c->source_url) === '');
$stale    = $active->filter(fn ($c) => ! $c->price_checked_at);
$quarant  = $active->filter(fn ($c) => isset($quarantined[(int) $c->id]));

printf("active components            : %d\n", $active->count());
printf("no source_url at all         : %d\n", $noSource->count());
printf("never price_checked (stale)  : %d\n", $stale->count());
printf("quarantined by integrity     : %d\n", $quarant->count());

// What the pool actually accepts: non-quarantined AND fresh AND evidenced.
$publishable = $active->reject(
    fn ($c) => isset($quarantined[(int) $c->id])
        || trim((string) $c->source_url) === ''
        || ! $integrity->freshness($c)['fresh']
);
printf("PUBLISHABLE right now        : %d\n", $publishable->count());

echo "\n--- by category: active / no-source / stale / publishable ---\n";
$rows = [];
foreach ($active as $c) {
    $n = $cats[$c->category_id] ?? '?';
    $rows[$n] ??= ['a' => 0, 'ns' => 0, 'st' => 0, 'p' => 0];
    $rows[$n]['a']++;
    if (trim((string) $c->source_url) === '') { $rows[$n]['ns']++; }
    if (! $c->price_checked_at)               { $rows[$n]['st']++; }
    if (! isset($quarantined[(int) $c->id]) && trim((string) $c->source_url) !== ''
        && $integrity->freshness($c)['fresh']) { $rows[$n]['p']++; }
}
printf("%-14s %6s %9s %7s %11s\n", 'category', 'active', 'no-source', 'stale', 'publishable');
foreach ($rows as $n => $r) {
    printf("%-14s %6d %9d %7d %11d\n", $n, $r['a'], $r['ns'], $r['st'], $r['p']);
}
