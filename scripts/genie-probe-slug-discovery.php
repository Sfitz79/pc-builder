<?php
// READ-ONLY: discover the REAL production slugs for the 3D target product
// families so the migration's exact-slug keys can be corrected (or confirmed).
$root = __DIR__ . '/..';
require $root . '/vendor/autoload.php';
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

// PRODUCTION GUARD: this script reads .env.production.neon. Assert the driver
// before querying, or a misconfigured run silently reports local sqlite data.
if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: expected the pgsql (Neon) driver, got '" . DB::connection()->getDriverName() . "'. Refusing to report production data.\n");
    exit(2);
}

$families = [
    'lian_li_o11'    => ['o11', 'lian-li'],
    'nzxt_h6'        => ['h6', 'nzxt-h6'],
    'fractal_north'  => ['fractal-north', 'north'],
    'hyte_y70'       => ['y70', 'hyte'],
    'rtx_5070'       => ['5070'],
    'rtx_5080'       => ['5080'],
    'rtx_5090'       => ['5090'],
    'rx_9070'        => ['9070', 'rx-9070'],
    'noctua_nh_d15'  => ['nh-d15', 'noctua'],
    'arctic_lf3_360' => ['freezer-iii', 'arctic-liquid'],
    'deepcool_ak620' => ['ak620', 'ak-620', 'deepcool'],
    'psu_650w'       => ['650w', '650-w'],
    'psu_850w'       => ['850w', '850-w'],
    'psu_1000w'      => ['1000w', '1000-w', '1200w'],
];

foreach ($families as $family => $tokens) {
    $seen = [];
    foreach ($tokens as $t) {
        $rows = DB::table('components')
            ->where('slug', 'ilike', '%' . $t . '%')
            ->get(['id', 'slug', 'name', 'category_id']);
        foreach ($rows as $r) {
            $seen[$r->slug] = ['id' => $r->id, 'name' => $r->name, 'cat' => $r->category_id];
        }
    }
    echo "### {$family} (matches=" . count($seen) . ")\n";
    foreach ($seen as $slug => $info) {
        echo "  slug={$slug}  id={$info['id']}  cat={$info['cat']}  name={$info['name']}\n";
    }
    echo "\n";
}
