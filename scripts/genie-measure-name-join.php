<?php
/**
 * Decide the image backfill strategy by measuring, not assuming.
 *
 * FINDING 2026-09-30: local SQLite ids run 1-2729 while production Neon ids run
 * 38930-41637. The 686 cache files that "match nothing locally" are ids
 * 38931-39627 - i.e. they ARE the production images. So there are no true
 * orphans, and id can never be the join key between the two databases.
 *
 * Both databases hold the same catalogue, so NAME is the candidate join key.
 * If name overlap is high, the whole image gap can be closed from CDN URLs we
 * ALREADY know, with no PCPP page rendering, no Byparr and no credit spend.
 *
 * READ-ONLY. Touches production with SELECT only.
 */
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

$root = dirname(__DIR__);

// ---- load production Neon credentials -------------------------------------
$prodEnv = $root . '/.env.production.neon';
if (!is_file($prodEnv)) {
    echo "FATAL: {$prodEnv} not found." . PHP_EOL;
    exit(1);
}
$e = [];
foreach (file($prodEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
        $e[$m[1]] = trim(trim($m[2]), "\"'");
    }
}
echo "PROD env keys found: " . implode(', ', array_keys($e)) . PHP_EOL;
$host = $e['DB_HOST'] ?? '';
echo "PROD host         : " . ($host ?: '(none)') . PHP_EOL . PHP_EOL;

// ---- local SQLite (name -> cdn url) ---------------------------------------
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "LOCAL driver      : " . config('database.default') . PHP_EOL;

$localRows = DB::table('components')
    ->whereNotNull('image_url')
    ->where('image_url', 'like', 'http%')
    ->get(['name', 'image_url']);
echo "LOCAL rows w/ CDN url: " . $localRows->count() . PHP_EOL;

// Build several normalisations so a formatting difference cannot masquerade
// as a genuine miss.
$norm = function (?string $s): string {
    $s = strtolower(trim((string) $s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
};
$byName = [];
foreach ($localRows as $r) {
    $k = $norm($r->name);
    if ($k !== '' && !isset($byName[$k])) $byName[$k] = $r->image_url; // first wins
}
echo "LOCAL unique normalised names: " . count($byName) . PHP_EOL . PHP_EOL;

// ---- production Neon (read-only) ------------------------------------------
$cfg = config('database.connections.pgsql');
Config::set('database.connections.prod_probe', [
    'driver'   => 'pgsql',
    'host'     => $e['DB_HOST'],
    'port'     => $e['DB_PORT'] ?? '5432',
    'database' => $e['DB_DATABASE'],
    'username' => $e['DB_USERNAME'],
    'password' => $e['DB_PASSWORD'],
    'charset'  => 'utf8',
    'prefix'   => '',
    'schema'   => 'public',
    'sslmode'  => 'require',
]);
DB::purge('prod_probe');

// Assert the driver. This script deliberately reads BOTH databases (local SQLite
// for the name map, Neon for the real rows), so the connection must be proven.
$prodDriver = DB::connection('prod_probe')->getDriverName();
if ($prodDriver !== 'pgsql') {
    echo "FATAL: prod_probe driver is '{$prodDriver}', expected 'pgsql'. Refusing to measure the wrong catalogue." . PHP_EOL;
    exit(4);
}
echo "PROD driver       : {$prodDriver} (asserted)" . PHP_EOL;

try {
    $prod = DB::connection('prod_probe')->table('components')->get(['id', 'name', 'image_url']);
    echo "PROD components    : " . $prod->count() . PHP_EOL;
    echo "PROD image_url set : " . $prod->filter(fn($r) => $r->image_url !== null)->count() . PHP_EOL . PHP_EOL;

    $hit = 0; $miss = 0; $already = 0;
    $missSamples = [];
    foreach ($prod as $r) {
        if ($r->image_url !== null && $r->image_url !== '') { $already++; continue; }
        $k = $norm($r->name);
        if ($k !== '' && isset($byName[$k])) { $hit++; }
        else {
            $miss++;
            if (count($missSamples) < 12) $missSamples[] = $r->name;
        }
    }

    echo "=== NAME-JOIN MATCH RESULT ===" . PHP_EOL;
    echo "  prod rows already stamped      : {$already}" . PHP_EOL;
    echo "  MISS prod rows, name MATCHES    : {$hit}   <- downloadable from known CDN urls, zero scraping" . PHP_EOL;
    echo "  MISS prod rows, no name match   : {$miss}   <- would still need PCPP page rendering" . PHP_EOL;
    echo "  potential new coverage          : " . round(100 * $hit / max(1, $prod->count()), 1) . "% of production catalogue" . PHP_EOL . PHP_EOL;

    if ($missSamples) {
        echo "NO-MATCH SAMPLES (these genuinely need a PCPP page fetch)" . PHP_EOL;
        foreach ($missSamples as $n) echo "  " . substr((string) $n, 0, 70) . PHP_EOL;
    }
} catch (Throwable $ex) {
    echo "PROD probe failed: " . get_class($ex) . ': ' . substr($ex->getMessage(), 0, 200) . PHP_EOL;
}
