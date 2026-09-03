<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Component;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

$gpuCat = Category::where('slug', 'gpu')->first();

// Get unique chipsets from GPU specs
$gpus = Component::where('category_id', $gpuCat->id)->whereNotNull('specs')->get();
$chipsets = [];
foreach ($gpus as $gpu) {
    $specs = is_string($gpu->specs) ? json_decode($gpu->specs, true) : $gpu->specs;
    $chipset = $specs['chipset'] ?? null;
    if ($chipset) {
        $chipsets[$chipset] = ($chipsets[$chipset] ?? 0) + 1;
    }
}
arsort($chipsets);
echo "=== GPU Chipsets ===\n";
foreach ($chipsets as $name => $count) {
    echo "  {$name} ({$count} models)\n";
}

// Get unique CPU sockets
$cpuCat = Category::where('slug', 'cpu')->first();
$sockets = Component::where('category_id', $cpuCat->id)->whereNotNull('socket')
    ->select('socket', DB::raw('count(*) as cnt'))
    ->groupBy('socket')
    ->orderByDesc('cnt')
    ->get();
echo "\n=== CPU Sockets ===\n";
foreach ($sockets as $s) {
    echo "  {$s->socket} ({$s->cnt} models)\n";
}

// Cooler count
$coolerCat = Category::where('slug', 'cooler')->first();
echo "\n=== Coolers ===\n";
echo "  Count: " . Component::where('category_id', $coolerCat->id)->count() . "\n";
