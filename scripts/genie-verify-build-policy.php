<?php
/*
 * READ-ONLY. Prove BuildPolicyGate against production data.
 *
 * For every hard gate, measure how many active priced rows PASS and how many
 * fail, with the reasons. A gate that empties its pool is a broken gate, so the
 * pass counts are the headline number, not just the failures.
 */
use App\Services\BuildPolicyGate;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

if (! str_contains((string) config('database.connections.pgsql.host'), 'neon.tech')) {
    echo "ABORT: not on Neon\n";
    exit(1);
}
echo "host: " . config('database.connections.pgsql.host') . "\n\n";

$gate = new BuildPolicyGate();
$cat = DB::table('categories')->pluck('id', 'name');
$rows = fn (string $c) => DB::table('components')
    ->where('category_id', $cat[$c])->where('active', 1)
    ->whereNotNull('price')->where('price', '>', 0)->get();

$report = function (string $label, $items, callable $fn) {
    $pass = 0;
    $reasons = [];
    foreach ($items as $i) {
        $r = $fn($i);
        if ($r['ok']) {
            $pass++;
        } else {
            $reasons[$r['reason']] = ($reasons[$r['reason']] ?? 0) + 1;
        }
    }
    printf("  %-34s %4d / %-4d pass\n", $label, $pass, $items->count());
    arsort($reasons);
    foreach (array_slice($reasons, 0, 4, true) as $why => $n) {
        printf("       %-32s %4d\n", $why, $n);
    }

    return $pass;
};

echo "=== CPU gate (AM4 >= 5500 model, Intel LGA1700+) ===\n";
$cpus = $rows('CPU');
$pass = $report('all active priced CPUs', $cpus, fn ($c) => $gate->cpuAllowed($c));
foreach (['AM4', 'AM5', 'LGA1700', 'LGA1851'] as $s) {
    $sub = $cpus->filter(fn ($c) => strtoupper((string) $c->socket) === $s);
    if ($sub->isEmpty()) {
        continue;
    }
    $p = $sub->filter(fn ($c) => $gate->cpuAllowed($c)['ok'])->count();
    printf("       %-8s %4d / %-4d pass\n", $s, $p, $sub->count());
}
printf("       8-core preference satisfied by %d of the passing CPUs\n",
    $cpus->filter(fn ($c) => $gate->cpuAllowed($c)['ok'] && $gate->prefersEightCores($c))->count());

echo "\n=== memory gate (16GB, 2x8 dual channel, socket generation) ===\n";
$ram = $rows('RAM');
foreach (['AM4' => 'DDR4', 'AM5' => 'DDR5'] as $sock => $gen) {
    // Memory has no socket of its own; pair it with each platform's requirement.
    $report("as required by {$sock} ({$gen})", $ram, fn ($r) => $gate->ramAllowed($r, $sock));
}

echo "\n=== storage gate (>=500GB SSD) ===\n";
$report('active priced storage', $rows('Storage'), fn ($s) => $gate->storageAllowed($s));
$m2 = $rows('Storage')->filter(fn ($s) => $gate->storageRank($s) === 0);
printf("  M.2 / NVMe preferred and available: %d\n", $m2->count());

echo "\n=== GPU gate (>=8GB VRAM, B570 / RTX 3050 8GB / RX 7000+) ===\n";
$gpus = $rows('GPU');
$report('active priced GPUs', $gpus, fn ($g) => $gate->gpuAllowed($g));

echo "\n=== board gate (AMD families only) ===\n";
foreach (['AM4', 'AM5'] as $s) {
    $sub = $rows('Motherboard')->filter(fn ($b) => strtoupper((string) $b->socket) === $s);
    if ($sub->isEmpty()) {
        continue;
    }
    $report("motherboards on {$s}", $sub, fn ($b) => $gate->boardAllowed($b));
}

echo "\n=== brand blocklist across ALL categories ===\n";
$all = DB::table('components')->where('active', 1)->whereNotNull('price')->get();
$blocked = [];
$kept = 0;
foreach ($all as $c) {
    $r = $gate->brandAllowed((string) $c->name, $c->manufacturer_name ?? null);
    if ($r['ok']) {
        $kept++;
    } else {
        $blocked[$r['reason']] = ($blocked[$r['reason']] ?? 0) + 1;
    }
}
printf("  %d / %d active priced rows pass the brand gate\n", $kept, $all->count());
arsort($blocked);
foreach ($blocked as $why => $n) {
    printf("       %-40s %4d\n", $why, $n);
}
$gig = $all->filter(fn ($c) => stripos((string) $c->name, 'Gigabyte') !== false);
printf("  Gigabyte rows: %d total, %d kept (AORUS/Elite carve-out)\n",
    $gig->count(), $gig->filter(fn ($c) => $gate->brandAllowed((string) $c->name)['ok'])->count());

echo "\n=== PSU efficiency is a RANKING, not a gate ===\n";
$psus = $rows('PSU');
$ranked = [];
foreach ($psus as $p) {
    $ranked[] = $gate->psuEfficiencyRank($p);
}
printf("  PSUs with a readable rating : %d / %d\n",
    count(array_filter($ranked, fn ($r) => $r['known'])), $psus->count());
printf("  PSUs with UNKNOWN rating   : %d  (ranked below Bronze, never excluded)\n",
    count(array_filter($ranked, fn ($r) => ! $r['known'])));

echo "\n=== does a realistic build survive every hard gate at once? ===\n";
$board = $rows('Motherboard')->first(fn ($b) => strtoupper($b->socket) === 'AM5' && $gate->boardAllowed($b)['ok']);
$cpu = $cpus->first(fn ($c) => strtoupper($c->socket) === 'AM5' && $gate->cpuAllowed($c)['ok'] && $gate->prefersEightCores($c));
$ramP = $ram->first(fn ($r) => $gate->ramAllowed($r, 'AM5')['ok']);
$sto = $rows('Storage')->first(fn ($s) => $gate->storageAllowed($s)['ok'] && $gate->storageRank($s) === 0);
$gpu = $gpus->first(fn ($g) => $gate->gpuAllowed($g)['ok']);

$res = $gate->buildAllowed(
    ['cpu' => $cpu, 'motherboard' => $board, 'ram' => $ramP, 'storage' => $sto, 'gpu' => $gpu],
    ['ram' => 'AM5']
);
printf("  combined verdict: %s\n", $res['ok'] ? 'PASS' : 'FAIL ' . json_encode($res['failures']));
foreach (compact('cpu', 'board', 'ramP', 'sto', 'gpu') as $k => $v) {
    if ($v) {
        printf("    %-6s %s\n", $k, substr((string) $v->name, 0, 52));
    }
}