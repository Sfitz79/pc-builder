<?php
// Names the exact rows blocking 100% real imagery, in two distinct classes:
//   A: no image_url at all
//   B: an image that exists but is under 4KB, i.e. placeholder-quality and would look fake
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('components')
    ->leftJoin('categories', 'components.category_id', '=', 'categories.id')
    ->where('components.active', true)
    ->get(['components.id', 'components.name', 'categories.slug as category', 'components.price', 'components.image_url']);
$total = $rows->count();

$missing = [];
$thin = [];
foreach ($rows as $r) {
    if ($r->image_url === null || trim((string) $r->image_url) === '') {
        $missing[] = $r;
        continue;
    }
    $rel = '/' . ltrim(str_replace('img/components/', '', $r->image_url), '/');
    $path = public_path(ltrim($rel, '/'));
    $size = is_file($path) ? filesize($path) : 0;
    if ($size > 0 && $size < 4096) {
        $thin[] = (object) ['id' => $r->id, 'name' => $r->name, 'category' => $r->category,
            'price' => $r->price, 'bytes' => $size, 'url' => $r->image_url];
    }
}

echo "active components          : {$total}\n";
echo "with a real image           : " . ($total - count($missing) - count($thin)) . "\n";
echo 'A. no image_url at all      : ' . count($missing) . "\n";
echo 'B. image under 4KB (fake)  : ' . count($thin) . "\n";
echo '-> NOT at 100%              : ' . (count($missing) + count($thin)) . "\n\n";

echo "=== A: MISSING (grouped by category) ===\n";
$byCat = [];
foreach ($missing as $r) { $byCat[$r->category][] = $r; }
foreach ($byCat as $cat => $list) {
    echo "  {$cat} (" . count($list) . ")\n";
    foreach ($list as $r) {
        echo '     #' . $r->id . '  ' . substr((string) $r->name, 0, 62) . '  GBP ' . $r->price . "\n";
    }
}

echo "\n=== B: PLACEHOLDER-QUALITY IMAGES ===\n";
foreach ($thin as $r) {
    echo '     #' . $r->id . '  ' . str_pad($r->category, 12) . number_format($r->bytes) . 'B  '
        . substr((string) $r->name, 0, 58) . "\n";
}
