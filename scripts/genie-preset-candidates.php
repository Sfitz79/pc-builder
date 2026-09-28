<?php
// Genie 2026-09-28: resolve researched spec targets onto REAL catalogue rows.
//
// Boss directive: 3 popular specs per tier, as pre-set systems with prices.
// Prices must never be typed in by me. They are summed from live catalogue
// component rows, so a preset is either buildable and correctly priced or it
// is not created. This script only READS and REPORTS candidates - it writes
// nothing. Nothing goes into the DB until the matches are reviewed, because
// publishing a preset pointing at a wrong or junk component is worse than
// having no preset.
//
// Tier naming follows the business decision: esports/starter, then 1080p, 1440p, 4K.
// The 1080p floor is the hardware that runs the latest top 150 games at
// publisher-recommended settings, so the 1080p spec is deliberately NOT a thin
// 1080p machine.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$cat = [];
foreach (DB::table('categories')->get() as $c) {
    $cat[strtolower($c->name)] = $c->id;
}

function live(string $like, ?int $catId = null, int $limit = 6): array
{
    $q = DB::table('components')->where('active', true)
        ->where('price', '>', 0)->whereNotNull('price');

    if ($catId) {
        $q->where('category_id', $catId);
    }
    if ($like !== '') {
        $q->where(function ($w) use ($like) {
            foreach (explode('|', $like) as $i => $t) {
                $i ? $w->orWhere('name', 'ILIKE', "%{$t}%") : $w->where('name', 'ILIKE', "%{$t}%");
            }
        });
    }

    return $q->orderBy('price')->limit($limit)->get()
        ->map(fn ($r) => sprintf('£%-8.2f #%-5d %s', $r->price, $r->id, mb_strimwidth((string) $r->name, 0, 58)))
        ->all();
}

$sections = [
    'CPU (esports/starter)' => ['RYZEN 5 5600|RYZEN 3 3200G|CORE I3-12100F|RYZEN 5 8600G', $cat['cpu'] ?? null],
    'CPU (1080p)' => ['RYZEN 5 9600|RYZEN 5 7600|CORE I5-12400F', $cat['cpu'] ?? null],
    'CPU (1440p)' => ['RYZEN 7 9700X|RYZEN 7 7700|CORE I5-13400F|CORE ULTRA 5 245K', $cat['cpu'] ?? null],
    'CPU (4K)' => ['RYZEN 7 9800X3D|RYZEN 9 9950X|RYZEN 9 9900X|RYZEN 7 7800X3D', $cat['cpu'] ?? null],
    'GPU (esports/starter)' => ['RTX 3050|ARC A310|GTX 1660|ARC B580|RTX 5050', $cat['gpu'] ?? null],
    'GPU (1080p)' => ['RTX 5060 TI|RX 9060 XT|RTX 5060|RX 7600', $cat['gpu'] ?? null],
    'GPU (1440p)' => ['RTX 5070|RX 9070 XT|RX 9070|RX 7800 XT', $cat['gpu'] ?? null],
    'GPU (4K)' => ['RTX 5070 TI|RTX 5080|RX 7900 XT|RTX 4090', $cat['gpu'] ?? null],
    'RAM 16GB DDR4' => ['16GB.*DDR4|16 ?GB.*(2X8|2 x 8)', $cat['ram'] ?? null],
    'RAM 32GB DDR5' => ['32GB.*DDR5|32 ?GB.*(2X16|2 x 16)', $cat['ram'] ?? null],
    'STORAGE 1TB NVMe' => ['1TB|NVMe.*1000', $cat['storage'] ?? null],
    'STORAGE 2TB NVMe' => ['2TB|NVMe.*2000', $cat['storage'] ?? null],
    'MOTHERBOARD AM4/B550' => ['B550|A520', $cat['motherboard'] ?? null],
    'MOTHERBOARD AM5 B650' => ['B650|B850|X670', $cat['motherboard'] ?? null],
    'PSU 550W GOLD' => ['550W', $cat['psu'] ?? null],
    'PSU 750W GOLD' => ['750W', $cat['psu'] ?? null],
    'PSU 850W GOLD' => ['850W', $cat['psu'] ?? null],
    'CASE mid tower' => ['MESH|220|400|TEMPEST|PRIME|400D|ANTC', $cat['case'] ?? null],
    'COOLER air' => ['PEAK|PA120|AK400|PURE ROCK|NOCTUA|U12|ASSASSIN', $cat['cooler'] ?? null],
];

echo "=== catalogue candidates for preset specs (read-only) ===".PHP_EOL.PHP_EOL;
foreach ($sections as $label => [$like, $catId]) {
    echo "--- $label ---".PHP_EOL;
    $rows = live($like, $catId);
    if (! $rows) {
        echo "  NO MATCH - tier cannot be built as researched".PHP_EOL;
    }
    foreach ($rows as $r) {
        echo "  $r".PHP_EOL;
    }
    echo PHP_EOL;
}
