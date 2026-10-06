<?php

/**
 * Pull REAL GPU identities (model + VRAM) out of PRODUCTION Postgres for the
 * pre-built ladder.
 *
 * The `chipset` COLUMN is empty on production GPU rows, so a column-only read
 * makes every card look unidentifiable - which is how you end up deleting a
 * whole category on a false conclusion. The model and VRAM live in the `specs`
 * JSON blob, which is what the storefront actually reads.
 */

$root = dirname(__DIR__);
$envFile = $root . '/.env.production.neon';

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

echo "=== production categories (exact names) ===\n";
foreach (DB::table('categories')->orderBy('id')->get() as $c) {
    printf("  %-3s %s\n", $c->id, $c->name);
}

$gpu = DB::table('categories')->where('name', 'like', '%GPU%')->orWhere('name', 'like', '%Graphics%')->first();
if (!$gpu) {
    fwrite(STDERR, "no GPU category\n");
    exit(3);
}

$rows = DB::table('components')
    ->where('category_id', $gpu->id)
    ->where('active', 1)
    ->whereNotNull('price')
    ->where('price', '>', 150)
    ->where('price', '<=', 1200)
    ->orderBy('price')
    ->get(['id', 'name', 'price', 'chipset', 'specs', 'image_url']);

printf("\n=== GPU rows: how many are identifiable? ===\n");
$byCol = $rows->filter(fn ($r) => filled($r->chipset))->count();
$bySpec = $rows->filter(function ($r) {
    $s = is_array($r->specs) ? $r->specs : (json_decode((string) $r->specs, true) ?: []);
    return filled($s['chipset'] ?? null) || filled($s['chipset_family'] ?? null);
})->count();
printf("  total in band        : %d\n", $rows->count());
printf("  chipset COLUMN set   : %d\n", $byCol);
printf("  specs.chipset set    : %d\n", $bySpec);

printf("\n=== identifiable GPUs, GBP150-1200 (real model from specs) ===\n");
$shown = 0;
foreach ($rows as $r) {
    $s = is_array($r->specs) ? $r->specs : (json_decode((string) $r->specs, true) ?: []);
    $model = $s['chipset'] ?? $r->chipset ?? null;
    if (blank($model)) {
        continue;
    }
    $vram = $s['vram'] ?? $s['memory'] ?? $s['memory_size'] ?? '?';
    printf(
        "  %-6s %-34s GBP%-6s vram:%-8s tier:%-4s img:%s\n",
        $r->id,
        substr((string) $model, 0, 34),
        number_format((float) $r->price, 0),
        (string) $vram,
        (string) ($s['tier'] ?? '?'),
        $r->image_url ? 'yes' : 'no'
    );
    $shown++;
    if ($shown >= 60) {
        break;
    }
}
printf("\n  (showing first %d identifiable)\n", $shown);

// What spec keys exist at all on GPU rows? Needed so the pre-built picks the
// right field rather than guessing a name that only exists locally.
printf("\n=== spec keys present on production GPU rows ===\n");
$keys = [];
foreach ($rows->take(120) as $r) {
    $s = is_array($r->specs) ? $r->specs : (json_decode((string) $r->specs, true) ?: []);
    foreach (array_keys($s) as $k) {
        $keys[$k] = ($keys[$k] ?? 0) + 1;
    }
}
arsort($keys);
foreach (array_slice($keys, 0, 18, true) as $k => $n) {
    printf("  %-20s %d\n", $k, $n);
}
