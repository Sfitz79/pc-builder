<?php
// Inspects the 43 "under 4KB" images. A legitimately small product photo can be tiny,
// so file size alone is not proof of a placeholder - LOOK at what they actually are.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('components')->where('active', true)->whereNotNull('image_url')->where('image_url', '<>', '')->get(['id', 'name', 'category_id', 'image_url']);
$cats = DB::table('categories')->pluck('slug', 'id');

$thin = [];
foreach ($rows as $r) {
    $rel = preg_replace('#^.*?img/components/#', '', (string) $r->image_url);
    $path = public_path('img/components/' . $rel);
    $size = is_file($path) ? filesize($path) : 0;
    if ($size > 0 && $size < 4096) {
        $thin[] = (object) ['id' => $r->id, 'name' => $r->name,
            'cat' => $cats[$r->category_id] ?? '?', 'bytes' => $size, 'file' => $rel];
    }
}

echo 'active components with an image under 4KB: ' . count($thin) . "\n\n";
$byCat = [];
foreach ($thin as $t) { $byCat[$t->cat][] = $t; }
foreach ($byCat as $cat => $list) {
    echo "  {$cat} (" . count($list) . ")\n";
    foreach ($list as $t) {
        printf("     #%d  %6d B  %-44s %s\n", $t->id, $t->bytes, substr((string) $t->name, 0, 44), $t->file);
    }
}

// Are these actually the same file? A shared placeholder would explain a cluster.
$byFile = [];
foreach ($thin as $t) { $byFile[$t->file][] = $t->id; }
$shared = array_filter($byFile, fn ($ids) => count($ids) > 1);
echo "\ndistinct image FILES among them: " . count($byFile) . "\n";
echo "files reused by more than one component: " . count($shared) . "\n";
foreach (array_slice($shared, 0, 6, true) as $f => $ids) {
    echo '   ' . $f . '  <- components ' . implode(', ', $ids) . "\n";
}