<?php
// READ-ONLY probe: which 3D-dimension slugs exist on production, and do they
// already carry dimension keys? Used to decide whether the 3D migration is a
// no-op or a real write. Never writes.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$slugs = [
    'lian-li-o11-vision', 'nzxt-h6-flow-rgb', 'fractal-north', 'hyte-y70',
    'rtx-5070-ti', 'rtx-5080', 'rtx-5090', 'rx-9070-xt',
    'noctua-nh-d15', 'arctic-liquid-freezer-iii-360', 'deepcool-ak620',
    '650w-80-gold', '850w-80-gold', '1000w-80-gold',
];

$rows = DB::table('components')->whereIn('slug', $slugs)->get(['id', 'slug', 'specs']);
echo "driver=" . DB::connection()->getDriverName() . "  matched=" . $rows->count() . " of " . count($slugs) . "\n";

$out = [];
foreach ($rows as $r) {
    $s = is_string($r->specs) && $r->specs ? json_decode($r->specs, true) : [];
    $s = is_array($s) ? $s : [];
    $has = array_intersect_key($s, array_flip(['height', 'width', 'depth', 'length', 'rad_top', 'rad_front', 'form', 'glass_panels', 'thickness_slots', 'type', 'radiator_length', 'fan_count']));
    $out[] = [
        'id' => $r->id,
        'slug' => $r->slug,
        'dim_keys_present' => array_keys($has),
        'specs_bytes' => strlen((string) $r->specs),
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
