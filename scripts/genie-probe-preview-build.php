<?php

/**
 * Probe for the build-preview feature.
 *
 * Prints one real active component per category (so the preview has a genuine
 * specification to render) and the id list to POST to /builder/preview-image.
 *
 * Usage: php scripts/genie-probe-preview-build.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$categories = ['gpu', 'cpu', 'motherboard', 'case', 'ram', 'storage', 'cooler', 'psu'];

$rows = Illuminate\Support\Facades\DB::table('components')
    ->join('categories', 'components.category_id', '=', 'categories.id')
    ->where('components.active', true)
    ->whereIn('categories.slug', $categories)
    ->orderBy('categories.slug')
    ->orderByDesc('components.id')
    ->get(['components.id', 'components.name', 'categories.slug']);

$want = [];
foreach ($rows as $r) {
    if (! isset($want[$r->slug])) {
        $want[$r->slug] = [(int) $r->id, $r->name];
    }
}

foreach ($want as $slug => $v) {
    echo "  {$slug}\tid={$v[0]}\t{$v[1]}\n";
}

$ids = array_map(static fn (array $v): int => $v[0], $want);
echo 'IDS='.implode(',', $ids)."\n";
echo 'COUNT='.count($ids)."\n";

echo "\nSTUDIO CONFIG\n";
echo '  aigen = '.config('aigenstudio.aigenUrl')."\n";
echo '  comfy = '.config('aigenstudio.comfyUrl')."\n";
$blocked = static fn (string $u): bool => (bool) preg_match(
    '#^https?://(127\.0\.0\.1|localhost|\[::1\]|0\.0\.0\.0)(:|/|$)#i',
    $u
);
echo '  bridge_blocked_by_localhost = '.($blocked(config('aigenstudio.aigenUrl')) ? 'YES' : 'no')."\n";
