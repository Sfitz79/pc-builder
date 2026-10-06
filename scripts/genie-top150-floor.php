<?php

/**
 * genie-top150-floor.php - derive the 1080p floor from the Steam top-150 dataset.
 *
 * The Boss directive is that the MINIMUM 1080p build must run the latest top
 * 150 games at publisher RECOMMENDED specs. So the floor is not a hand-picked
 * number: it is whatever the most demanding recommended GPU/CPU in that dataset
 * demands, priced as a real, coherent build out of our own catalogue.
 *
 * READ-ONLY. It writes nothing to the database. It prints the evidence and the
 * arithmetic so the decision can be defended, and it records the top titles
 * that set each ceiling rather than just asserting a number.
 *
 * Usage:
 *   php scripts\genie-top150-floor.php               (read production Neon)
 *   DB_CONNECTION=sqlite php scripts\genie-top150-floor.php   (local sqlite)
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$datasetFile = __DIR__.'/../database/scraped/steam-top150-requirements.json';
if (! is_file($datasetFile)) {
    fwrite(STDERR, "FATAL: no top-150 dataset at {$datasetFile}\n");
    exit(1);
}
$data = json_decode(file_get_contents($datasetFile), true);
$games = $data['games'] ?? [];

$withRec = array_filter($games, fn ($g) => ! empty($g['gpu_recommended']) && ! empty($g['cpu_recommended']));

printf("== SOURCE ==\n");
printf("  chart        : %s\n", $data['_meta']['source_chart'] ?? 'unknown');
printf("  requirements : %s\n", $data['_meta']['source_requirements'] ?? 'unknown');
printf("  rollup date  : %s\n", $data['_meta']['captured_at'] ?? 'unknown');
printf("  titles held  : %d\n", count($games));
printf("  with both recommended GPU and CPU: %d\n\n", count($withRec));

/**
 * Rank GPUs by a coarse performance order so "most demanding" is a measurement
 * rather than an opinion. The list is ordered worst -> best.
 */
$gpuOrder = [
    'integrated' => 0,
    'gtx 1050' => 5, 'gtx 1060' => 15, 'rx 570' => 16, 'gtx 1070' => 20,
    'gtx 1080' => 30, 'rx 580' => 31, 'rx 590' => 32, 'gtx 1660' => 25,
    'rx 6500' => 20, 'arc a380' => 22, 'arc a310' => 10,
    'rtx 2050' => 24, 'rtx 2060' => 33, 'rx 6600' => 34, 'rx 5600' => 35,
    'rtx 3050' => 30, 'arc a580' => 38, 'rtx 3060' => 40,
    'rtx 4060' => 45, 'rx 7600' => 46, 'arc a750' => 47,
    'rtx 3060 ti' => 50, 'rtx 2080' => 52, 'rx 6700' => 55, 'rx 6600 xt' => 53,
    'rtx 4060 ti' => 55, 'rtx 3070' => 58, 'rx 6700 xt' => 60, 'rtx 3070 ti' => 62,
    'rx 6800 xt' => 64, 'rtx 4070' => 70, 'rtx 5060 ti' => 72, 'rx 9060 xt' => 74,
    'rtx 5070' => 80, 'rx 7700 xt' => 82, 'rx 7800 xt' => 88, 'rtx 4070 ti' => 90,
    'rtx 5070 ti' => 95, 'rtx 5080' => 120, 'rtx 5090' => 140, 'rx 9070' => 125,
    'rx 7900 xt' => 110, 'rx 7900 xtx' => 130, 'rtx 3080' => 90, 'rtx 3090' => 130,
    'rtx 4090' => 200, 'rtx 4080' => 170,
];

/** Score a requirement string; null when the text states no model we can rank. */
function gpuScore(?string $req, array $order): ?int
{
    if ($req === null || trim($req) === '') {
        return null;
    }
    $h = strtolower($req);
    $best = null;
    foreach ($order as $needle => $score) {
        if ($h !== '' && str_contains($h, $needle)) {
            $best = $best === null ? $score : max($best, $score);
        }
    }

    return $best;
}

$scored = [];
foreach ($withRec as $appid => $g) {
    $s = gpuScore($g['gpu_recommended'], $gpuOrder);
    if ($s !== null) {
        $scored[] = ['score' => $s, 'appid' => $appid] + $g;
    }
}
usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

printf("== MOST DEMANDING RECOMMENDED GPUs (top 12 of %d scorable) ==\n", count($scored));
foreach (array_slice($scored, 0, 12) as $t) {
    printf("  #%-3d %-38s score=%-4d %s\n", $t['rank'], $t['name'], $t['score'], $t['gpu_recommended']);
}

$ceiling = $scored[0] ?? null;

printf("\n== BINDING GPU CEILING ==\n");
if (! $ceiling) {
    fwrite(STDERR, "FATAL: no recommended GPU in the dataset could be ranked - refusing to guess a floor\n");
    exit(1);
}
printf("  highest demanded : %s (score %d)\n", $ceiling['gpu_recommended'], $ceiling['score']);
printf("  demanded by ranks: %s\n", implode(', ', array_map(fn ($t) => '#'.$t['rank'].' '.$t['name'], array_slice($scored, 0, 6))));

// The floor must clear EVERY title, so the target card is the cheapest class
// whose score meets the ceiling. Sort the candidates by score first: walking a
// hand-ordered list would just return the first entry that happens to be
// listed, which is how "cheapest class" came back as an RTX 5070.
$candidates = [];
foreach ([
    'rtx 3070' => 'RTX 3070',
    'rx 6700 xt' => 'RX 6700 XT',
    'rtx 3060 ti' => 'RTX 3060 Ti',
    'rx 6800 xt' => 'RX 6800 XT',
    'rtx 3070 ti' => 'RTX 3070 Ti',
    'rtx 4070' => 'RTX 4070',
    'rtx 5070' => 'RTX 5070',
] as $needle => $label) {
    $candidates[$label] = $gpuOrder[$needle];
}
asort($candidates);
$targetClass = null;
foreach ($candidates as $label => $score) {
    if ($score >= $ceiling['score']) {
        $targetClass = $label;
        break;
    }
}
printf("  cheapest class clearing it: %s\n", $targetClass ?? 'NONE IN TABLE');

printf("\n== CATALOGUE CANDIDATES (%s) ==\n", config('database.default'));

$findGpu = App\Models\Component::where('active', true)
    ->where('category_id', 4)
    ->where(function ($q) {
        $q->where('chipset', 'like', '%3070%')->orWhere('chipset', 'like', '%6700%')
          ->orWhere('chipset', 'like', '%6800%')->orWhere('chipset', 'like', '%4070%')
          ->orWhere('chipset', 'like', '%3060%');
    })
    ->orderBy('price')
    ->get();

// gpuPerformanceTier is protected, so reach it explicitly rather than
// guessing at a public wrapper that may not exist.
$svc = app(App\Services\AIRecommendationService::class);
$tierMethod = new ReflectionMethod($svc, 'gpuPerformanceTier');
$tierMethod->setAccessible(true);

foreach ($findGpu as $g) {
    printf("  #%-6s %-46s £%-8s stock=%-4s tier=%s\n",
        $g->id,
        substr($g->name, 0, 46),
        number_format((float) $g->price, 2),
        (string) $g->stock,
        (string) ($tierMethod->invoke($svc, $g) ?? 'n/a')
    );
}

printf("\n== CPU DEMAND ==\n");
$cpuC = $withRec;
usort($cpuC, fn ($a, $b) => $b['rank'] <=> $a['rank']);
foreach ($cpuC as $g) {
    if (preg_match('/i[579]-1[0-9]{4}|Ryzen\s?9|Ryzen\s?7\s?[0-9]{4}/i', (string) $g['cpu_recommended'])) {
        printf("  #%-3d %-38s %s\n", $g['rank'], $g['name'], $g['cpu_recommended']);
    }
}
