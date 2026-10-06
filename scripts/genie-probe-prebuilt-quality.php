<?php
// Genie 2026-09-29: before writing any quality rule, measure the real catalogue.
//
// The price-first picker produced a "High End" build with a £17.99 case and a
// £67.50 board. Before choosing replacement rules I need to know what is
// actually on the shelf, what identifies a quality part, and how far the
// evidence can distinguish one. Guessing thresholds here would just produce a
// different bad build with more confidence.
//
// Read-only. Connects to whichever DB the env points at; prints the driver so
// a local number is never mistaken for production.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'driver: ' . DB::connection()->getDriverName() . '  (' . DB::connection()->getDatabaseName() . ")\n\n";

function specsOf(object $r): array
{
    $d = $r->specs ?? null;
    if (is_string($d)) {
        $d = json_decode($d, true) ?: [];
    }

    return is_array($d) ? $d : [];
}

$ids = DB::table('categories')->pluck('id', 'name');

function cat(string $name): ?int
{
    global $ids;

    return $ids[$name] ?? null;
}

foreach (['CPU', 'GPU', 'Motherboard', 'RAM', 'Storage', 'PSU', 'Case', 'Cooler'] as $c) {
    $id = cat($c);
    if (! $id) {
        echo "MISSING CATEGORY {$c}\n";
        continue;
    }
    $q = DB::table('components')->where('category_id', $id)->where('active', 1)->whereNotNull('price');
    printf("=== %-12s %d priced rows ===\n", $c, $q->count());

    // What identity fields actually exist? This is the Rule 1 question: the
    // consumer is the picker, so measure every field the picker could read.
    $rows = $q->get();
    $keys = [];
    foreach ($rows as $r) {
        foreach (array_keys(specsOf($r)) as $k) {
            $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
    }
    arsort($keys);
    $top = array_slice($keys, 0, 8, true);
    $out = [];
    foreach ($top as $k => $n) {
        $out[] = $k . '=' . $n;
    }
    echo '   spec keys: ' . implode('  ', $out) . "\n";
    echo '   price: ' . round($q->min('price')) . ' .. ' . round($q->max('price')) . "\n";
}
