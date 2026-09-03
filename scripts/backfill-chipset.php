<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Backfill GPU chipsets
$gpuCatId = DB::table('categories')->where('slug', 'gpu')->value('id');
$gpuComponents = DB::table('components')
    ->where('category_id', $gpuCatId)
    ->whereNotNull('specs')
    ->orderBy('id')
    ->get();

$count = 0;
foreach ($gpuComponents as $component) {
    $specs = json_decode($component->specs, true);
    $chipset = $specs['chipset'] ?? null;
    if ($chipset && !$component->chipset) {
        DB::table('components')->where('id', $component->id)->update(['chipset' => $chipset]);
        $count++;
    }
}
echo "Backfilled {$count} GPU chipsets\n";

// Backfill CPU chipsets
$cpuCatId = DB::table('categories')->where('slug', 'cpu')->value('id');
$cpus = DB::table('components')->where('category_id', $cpuCatId)->orderBy('id')->get();
$count = 0;
foreach ($cpus as $cpu) {
    $upper = strtoupper($cpu->name);
    $chipset = null;

    if (preg_match('/RYZEN\s*5\s*9[56]00X?/', $upper)) $chipset = 'Ryzen 5 9600X';
    elseif (preg_match('/RYZEN\s*5\s*7[56]00X3D/', $upper)) $chipset = 'Ryzen 5 7600X3D';
    elseif (preg_match('/RYZEN\s*5\s*7600X\b/', $upper)) $chipset = 'Ryzen 5 7600X';
    elseif (preg_match('/RYZEN\s*5\s*7600\b/', $upper)) $chipset = 'Ryzen 5 7600';
    elseif (preg_match('/RYZEN\s*7\s*9800X3D/', $upper)) $chipset = 'Ryzen 7 9800X3D';
    elseif (preg_match('/RYZEN\s*7\s*9850X3D/', $upper)) $chipset = 'Ryzen 7 9800X3D';
    elseif (preg_match('/RYZEN\s*7\s*9700X/', $upper)) $chipset = 'Ryzen 7 9700X';
    elseif (preg_match('/RYZEN\s*7\s*7800X3D/', $upper)) $chipset = 'Ryzen 7 7800X3D';
    elseif (preg_match('/RYZEN\s*7\s*7700X/', $upper)) $chipset = 'Ryzen 7 7700X';
    elseif (preg_match('/RYZEN\s*7\s*7700\b/', $upper)) $chipset = 'Ryzen 7 7700';
    elseif (preg_match('/RYZEN\s*9\s*9900X/', $upper)) $chipset = 'Ryzen 9 9900X';
    elseif (preg_match('/RYZEN\s*9\s*9950X/', $upper)) $chipset = 'Ryzen 9 9950X';
    elseif (preg_match('/RYZEN\s*9\s*7900X/', $upper)) $chipset = 'Ryzen 9 7900X';
    elseif (preg_match('/RYZEN\s*9\s*7950X/', $upper)) $chipset = 'Ryzen 9 7950X';
    elseif (preg_match('/CORE\s*ULTRA\s*9\s*285K/', $upper)) $chipset = 'Core Ultra 9 285K';
    elseif (preg_match('/CORE\s*ULTRA\s*7\s*265K/', $upper)) $chipset = 'Core Ultra 7 265K';
    elseif (preg_match('/CORE\s*ULTRA\s*5\s*245K/', $upper)) $chipset = 'Core Ultra 5 245K';
    elseif (preg_match('/CORE\s*I9-14900K/', $upper)) $chipset = 'Core i9-14900K';
    elseif (preg_match('/CORE\s*I7-14700K/', $upper)) $chipset = 'Core i7-14700K';
    elseif (preg_match('/CORE\s*I5-14600K/', $upper)) $chipset = 'Core i5-14600K';
    elseif (preg_match('/CORE\s*I9-13900K/', $upper)) $chipset = 'Core i9-13900K';
    elseif (preg_match('/CORE\s*I7-13700K/', $upper)) $chipset = 'Core i7-13700K';
    elseif (preg_match('/CORE\s*I5-13600K/', $upper)) $chipset = 'Core i5-13600K';
    elseif (preg_match('/CORE\s*I5-12400/', $upper)) $chipset = 'Core i5-12400';
    elseif (preg_match('/CORE\s*I5-12600K/', $upper)) $chipset = 'Core i5-12600K';

    if ($chipset && !$cpu->chipset) {
        DB::table('components')->where('id', $cpu->id)->update(['chipset' => $chipset]);
        $count++;
    }
}
echo "Backfilled {$count} CPU chipsets\n";

// Summary
$gpuChipsets = DB::table('components')->where('category_id', $gpuCatId)->whereNotNull('chipset')
    ->select('chipset', DB::raw('count(*) as cnt'))->groupBy('chipset')->orderByDesc('cnt')->get();
echo "\n=== GPU Chipset Coverage ===\n";
foreach ($gpuChipsets as $g) {
    echo "  {$g->chipset}: {$g->cnt} models\n";
}

$cpuChipsets = DB::table('components')->where('category_id', $cpuCatId)->whereNotNull('chipset')
    ->select('chipset', DB::raw('count(*) as cnt'))->groupBy('chipset')->orderByDesc('cnt')->get();
echo "\n=== CPU Chipset Coverage ===\n";
foreach ($cpuChipsets as $c) {
    echo "  {$c->chipset}: {$c->cnt} models\n";
}
