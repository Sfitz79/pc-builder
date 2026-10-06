<?php

/**
 * Probe the DEDICATED columns for the data needed to assemble compatible
 * pre-builts. AGENTS rule 1: measure against every field the consumer reads -
 * the dedicated columns, not just the specs JSON.
 */

$root = dirname(__DIR__);
foreach (file($root . '/.env.production.neon', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim(trim($v), "\"'");
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: not pgsql\n");
    exit(2);
}

$cats = [];
foreach (DB::table('categories')->get() as $c) {
    $cats[$c->name] = $c->id;
}

foreach (['CPU', 'Motherboard', 'Case', 'Cooler', 'PSU'] as $name) {
    $id = $cats[$name];
    $tot = DB::table('components')->where('category_id', $id)->where('active', 1)->count();
    $soc = DB::table('components')->where('category_id', $id)->where('active', 1)->whereNotNull('socket')->where('socket', '<>', '')->count();
    $wat = DB::table('components')->where('category_id', $id)->where('active', 1)->whereNotNull('wattage')->where('wattage', '>', 0)->count();
    $chi = DB::table('components')->where('category_id', $id)->where('active', 1)->whereNotNull('chipset')->where('chipset', '<>', '')->count();
    printf("%-12s active:%-5d socket set:%-5d wattage set:%-5d chipset set:%d\n", $name, $tot, $soc, $wat, $chi);
}

echo "\n=== distinct CPU sockets ===\n";
foreach (DB::table('components')->where('category_id', $cats['CPU'])->where('active', 1)->whereNotNull('socket')->where('socket', '<>', '')
    ->select('socket', DB::raw('count(*) as n'))->groupBy('socket')->orderBy('n', 'desc')->get() as $r) {
    printf("  %-14s %d\n", $r->socket, $r->n);
}

echo "\n=== distinct Motherboard sockets ===\n";
foreach (DB::table('components')->where('category_id', $cats['Motherboard'])->where('active', 1)->whereNotNull('socket')->where('socket', '<>', '')
    ->select('socket', DB::raw('count(*) as n'))->groupBy('socket')->orderBy('n', 'desc')->get() as $r) {
    printf("  %-14s %d\n", $r->socket, $r->n);
}

echo "\n=== Motherboard: chipset column sample (form factor lives here?) ===\n";
foreach (DB::table('components')->where('category_id', $cats['Motherboard'])->where('active', 1)->whereNotNull('chipset')->where('chipset', '<>', '')
    ->whereNotNull('price')->where('price', '>', 60)->orderBy('price')->get(['id', 'name', 'price', 'socket', 'chipset'])->take(12) as $m) {
    printf("  %-6s %-30s GBP%-6s sock:%-8s %s\n", $m->id, substr((string) $m->name, 0, 30), number_format((float) $m->price, 0), (string) $m->socket, substr((string) $m->chipset, 0, 34));
}

echo "\n=== CPU: real models (name is partial, so pair name+chipset) ===\n";
foreach (DB::table('components')->where('category_id', $cats['CPU'])->where('active', 1)->whereNotNull('price')->where('price', '>', 60)->where('price', '<=', 700)
    ->orderBy('price')->get(['id', 'name', 'price', 'socket', 'chipset', 'specs'])->take(20) as $c) {
    $s = is_array($c->specs) ? $c->specs : (json_decode((string) $c->specs, true) ?: []);
    printf(
        "  %-6s %-26s GBP%-6s sock:%-8s cores:%-3s thr:%-3s chipset:%s\n",
        $c->id,
        substr((string) $c->name, 0, 26),
        number_format((float) $c->price, 0),
        (string) $c->socket,
        (string) ($s['cores'] ?? '?'),
        (string) ($s['threads'] ?? '?'),
        substr((string) $c->chipset, 0, 26)
    );
}

echo "\n=== Case: name sample (form factor may only exist in the name) ===\n";
foreach (DB::table('components')->where('category_id', $cats['Case'])->where('active', 1)->whereNotNull('price')->where('price', '>', 30)->where('price', '<=', 200)
    ->orderBy('price')->get(['id', 'name', 'price', 'chipset', 'socket'])->take(10) as $c) {
    printf("  %-6s %-38s GBP%-6s chipset:%-18s socket:%s\n", $c->id, substr((string) $c->name, 0, 38), number_format((float) $c->price, 0), substr((string) $c->chipset, 0, 18), (string) $c->socket);
}

echo "\n=== RAM: capacity/speed from specs ===\n";
foreach (DB::table('components')->where('category_id', $cats['RAM'])->where('active', 1)->whereNotNull('price')->where('price', '>', 30)->where('price', '<=', 160)
    ->orderBy('price')->get(['id', 'name', 'price', 'specs'])->take(10) as $r) {
    $s = is_array($r->specs) ? $r->specs : (json_decode((string) $r->specs, true) ?: []);
    printf("  %-6s %-26s GBP%-6s cap:%-8s speed:%s\n", $r->id, substr((string) $r->name, 0, 26), number_format((float) $r->price, 0), (string) ($s['capacity'] ?? '?'), (string) ($s['speed'] ?? '?'));
}
