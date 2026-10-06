<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ThreeD\BuildSceneService;
use App\Services\ThreeD\CaseGeometry;
use Illuminate\Support\Facades\Cache;

// 1. Does the anchor helper itself return the corrected value?
$cg = new CaseGeometry();
$a = $cg->anchors(
    ['x' => 230, 'y' => 460, 'z' => 460, 'glass' => 'side', 'max_gpu' => 400, 'max_cooler' => 170, 'rad_top' => 360, 'rad_front' => 360, 'form' => 'mid-tower'],
    [],
    '',
    'case'
);
echo "CaseGeometry::anchors() board_origin = [" . implode(', ', $a['board_origin']) . "]\n";
echo '  expect x = -(115 - 12) = -103.0';
echo ($a['board_origin'][0] < 0 ? "  OK\n\n" : "  STILL POSITIVE\n\n");

// 2. Is the scene being served from Cache?
echo "Cache driver : " . config('cache.default') . "\n";
$n = Cache::get('probe:mesh:' . md5(json_encode($a)));
echo "cached key present: " . ($n ? 'yes' : 'no') . "\n\n";

// 3. Where does the motherboard mesh actually land, and along which axis?
$sel = [];
foreach (['gpu', 'motherboard', 'cpu', 'ram', 'storage', 'psu', 'case', 'cooler'] as $cat) {
    $r = DB::table('components')->join('categories', 'components.category_id', '=', 'categories.id')
        ->where('categories.slug', $cat)->where('components.active', true)
        ->select('components.id')->orderBy('components.id')->first();
    if ($r) {
        $sel[$cat] = ['id' => (int) $r->id];
    }
}
$spec = (new BuildSceneService(new \App\Services\PartDimensions()))->forSelection($sel);

foreach ($spec['parts'] as $p) {
    if ($p['category'] !== 'motherboard') {
        continue;
    }
    echo "motherboard part t    = [" . implode(', ', $p['t']) . "]\n";
    echo "motherboard basis[0]  = [" . implode(', ', $p['basis'][0]) . "]\n";
    echo "motherboard basis[1]  = [" . implode(', ', $p['basis'][1]) . "]\n";
    echo "motherboard basis[2]  = [" . implode(', ', $p['basis'][2]) . "]\n\n";
    echo "  basis[0] is case-space direction of board-local X (board LENGTH)\n";
    echo "  basis[1] is board-local Y (height)\n";
    echo "  basis[2] is board-local Z (THICKNESS)\n\n";
    foreach ($p['meshes'] as $m) {
        if (($m['m'] ?? '') === 'pcb') {
            echo "  pcb mesh  s (size) = [" . implode(', ', $m['s']) . "]\n";
            echo "  pcb mesh  t (pos)  = [" . implode(', ', $m['t']) . "]\n";
            $lo = $m['t'][0] - $m['s'][0] / 2;
            $hi = $m['t'][0] + $m['s'][0] / 2;
            echo "  => occupies case X {$lo} .. {$hi}  (case interior is -115 .. 115)\n";
            $loZ = $m['t'][2] - $m['s'][2] / 2;
            $hiZ = $m['t'][2] + $m['s'][2] / 2;
            echo "  => occupies case Z {$loZ} .. {$hiZ}  (case interior is -230 .. 230)\n";
        }
    }
}
