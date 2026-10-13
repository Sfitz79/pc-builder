<?php

/**
 * Does the CPU policy gate ACTUALLY block pre-Zen3 Ryzen?
 *
 * WHY A PROBE AND NOT A READ-THROUGH
 * ----------------------------------
 * config/build_policy.php sets cpu.am4_min_model = 5500, and BuildPolicyGate::cpuAllowed()
 * appears to reject anything below it. Reading that code says a Ryzen 5 3600 cannot reach a
 * customer. Customers were still being offered 3600/3700/3900 parts, so the code was not
 * telling the truth - which means the gate has to be EXERCISED against the real catalogue,
 * not inspected.
 *
 * This runs the real BuildPolicyGate over every active CPU in production Postgres and
 * reports the pre-Zen3 parts that PASS. Those are the leak: parts the gate believes are
 * acceptable for a new build.
 *
 * Read-only. Touches nothing.
 *
 * Usage: php scripts/genie-probe-cpu-floor.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\BuildPolicyGate;
use Illuminate\Support\Facades\DB;

$gate = app(BuildPolicyGate::class);

$cpus = DB::table('components')
    ->join('categories', 'components.category_id', '=', 'categories.id')
    ->leftJoin('manufacturers', 'components.manufacturer_id', '=', 'manufacturers.id')
    ->where('categories.slug', 'cpu')
    ->where('components.active', true)
    ->orderBy('components.name')
    ->get([
        'components.id',
        'components.name',
        'components.socket',
        'manufacturers.name as manufacturer_name',
    ]);

echo 'ACTIVE CPUs TESTED AGAINST THE REAL GATE: '.$cpus->count()."\n\n";

/** Pre-Zen3 Ryzen: a desktop Ryzen part whose generation is below 5000. */
function isPreZen3Ryzen(string $name): bool
{
    return preg_match('/ryzen\s+\d\s+(\d{4})(?!\d)/i', $name, $m) === 1
        && (int) $m[1] < 5000;
}

$leaks = [];
$blocked = 0;

foreach ($cpus as $c) {
    $verdict = $gate->cpuAllowed($c);
    $ok = (bool) ($verdict['ok'] ?? false);

    if (! isPreZen3Ryzen($c->name)) {
        continue;
    }

    if ($ok) {
        $leaks[] = [
            'id' => $c->id,
            'name' => $c->name,
            'stored_socket' => (string) $c->socket,
        ];
    } else {
        $blocked++;
    }
}

echo "PRE-ZEN3 RYZEN CPUs: ".($blocked + count($leaks))."\n";
echo "  blocked by the gate : {$blocked}\n";
echo "  ALLOWED BY THE GATE : ".count($leaks)."   <-- these can reach a customer\n\n";

if (! $leaks) {
    echo "No leak on the gate itself. If the builder still shows these, the bug is that\n";
    echo "some path never calls the gate (a controller that lists CPUs without it).\n";
} else {
    echo "EVERY PRE-ZEN3 RYZEN CPU THE GATE ACCEPTS\n";
    foreach ($leaks as $l) {
        echo '  '.str_pad((string) $l['id'], 7).'  '.$l['name'];
        echo '    [stored socket: '.($l['stored_socket'] !== '' ? $l['stored_socket'] : 'NULL')."]\n";
    }
}

echo "\nSANITY: a modern Ryzen the gate must still accept\n";
foreach ($cpus as $c) {
    if (stripos($c->name, '7800X3D') !== false || stripos($c->name, '9800X3D') !== false || stripos($c->name, '5600X') !== false) {
        $v = $gate->cpuAllowed($c);
        echo '  '.($v['ok'] ? 'ALLOW' : 'BLOCK').'  '.$c->name;
        echo '    ['.($v['reason'] ?? 'ok')."]\n";
    }
}
