<?php
// READ-ONLY verification probe. Confirms which DB we reached, total component
// count, and whether the 3D-migration target slugs exist / already have dims.
// Never writes. Runs against production Neon using .env.production.neon.
$root = __DIR__ . '/..';
require $root . '/vendor/autoload.php';

// Load .env.production.neon into the process env (direct host = DDL-safe).
$envFile = $root . '/.env.production.neon';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim(trim($v), '"'));
    $_ENV[trim($k)] = trim(trim($v), '"');
}
putenv('APP_ENV=production');

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conn = DB::connection();
$cfg = $conn->getConfig();
echo "driver=" . $conn->getDriverName() . "\n";
echo "host=" . ($cfg['host'] ?? '?') . "\n";
echo "database=" . ($cfg['database'] ?? '?') . "\n";
$total = DB::table('components')->count();
echo "total_components=" . $total . "\n";

$slugs = [
    'lian-li-o11-vision', 'nzxt-h6-flow-rgb', 'fractal-north', 'hyte-y70',
    'rtx-5070-ti', 'rtx-5080', 'rtx-5090', 'rx-9070-xt',
    'noctua-nh-d15', 'arctic-liquid-freezer-iii-360', 'deepcool-ak620',
    '650w-80-gold', '850w-80-gold', '1000w-80-gold',
];
$matched = DB::table('components')->whereIn('slug', $slugs)->pluck('slug');
echo "matched_exact=" . $matched->count() . " of " . count($slugs) . "\n";
echo "matched_list=" . json_encode($matched) . "\n";

// Fuzzy: do components with these key tokens exist under a different slug?
foreach (['5070', '5080', '5090', 'north', 'ak620'] as $tok) {
    $n = DB::table('components')->where('slug', 'ilike', '%' . $tok . '%')->count();
    echo "slug_ilike_{$tok}=" . $n . "\n";
}
