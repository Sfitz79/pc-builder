<?php
// Genie 2026-09-29: the case picker has produced a £17.99 KOLINK in a machine
// called "High End 1440p", and the cooler picker is a name regex on "air|cooler|
// tower|120|140" applied to a price-ordered list. Before adding a quality floor I
// need to know which of the two problems is EVIDENCE-SOLVABLE.
//
// Specifically:
//   a) do cases carry max_gpu_length? 13 rows did locally - is that 13 in
//      production, and do those 13 cover the GPUs we would actually pair?
//   b) do GPUs carry length? only 4 locally. A 4/306 GPU figure is not a
//      constraint, it is a footnote.
//   c) is there any structured cooler data at all, or is the name the ONLY
//      signal - in which case a name whitelist is the honest approach and a
//      "sockets/RPM" schema would be fabrication.
//
// Read-only against PRODUCTION. No thresholds are invented here.
require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
foreach (file($root . '/.env.production.neon') as $line) {
    if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
        $v = trim($m[2]);
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        putenv($m[1] . '=' . $v);
        $_ENV[$m[1]] = $v;
    }
}
putenv('DB_CONNECTION=pgsql');
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once $root . '/scripts/genie-prod-guard.php';

function specsOf(object $r): array
{
    $s = $r->specs ?? null;
    if (is_string($s)) {
        $s = json_decode($s, true) ?: [];
    }
    return is_array($s) ? $s : [];
}

echo "PRODUCTION " . DB::connection()->getDriverName() . "\n";
$cats = DB::table('categories')->pluck('id', 'name');

function specKeyCounts(int $catId): array
{
    $rows = DB::table('components')->where('category_id', $catId)->where('active', 1)->whereNotNull('price')->get();
    $keys = [];
    foreach ($rows as $r) {
        foreach (array_keys(specsOf($r)) as $k) {
            $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
    }
    arsort($keys);

    return [$rows->count(), $keys];
}

foreach (['Case', 'Cooler', 'GPU', 'PSU'] as $c) {
    [$total, $keys] = specKeyCounts($cats[$c]);
    echo "\n=== {$c} (priced={$total}) spec key coverage ===\n";
    foreach ($keys as $k => $n) {
        printf("   %-24s %4d / %d  (%.0f%%)\n", $k, $n, $total, $n / $total * 100);
    }
    if (! $keys) {
        echo "   (no structured specs on any row - name is the only signal)\n";
    }
}

echo "\n=== Case price distribution, ATX/E-ATX only ===\n";
$atx = DB::table('components')->where('category_id', $cats['Case'])->where('active', 1)->whereNotNull('price')->get()
    ->filter(fn ($c) => in_array(specsOf($c)['form_factor'] ?? null, ['ATX', 'E-ATX'], true));
$prices = $atx->map(fn ($c) => (float) $c->price)->sort()->values();
printf("  n=%d  min=£%.2f  p10=£%.2f  median=£%.2f  p90=£%.2f  max=£%.2f\n",
    $prices->count(), $prices->min(), $prices->get((int) ($prices->count() * 0.1)),
    $prices->get((int) ($prices->count() * 0.5)), $prices->get((int) ($prices->count() * 0.9)), $prices->max());

echo "\n  the 8 CHEAPEST ATX cases (what orderBy(price) returns today):\n";
foreach ($atx->sortBy('price')->take(8) as $c) {
    printf("    £%-8.2f  %s\n", $c->price, $c->name);
}

echo "\n=== How many ATX cases cost at least the floor? ===\n";
foreach ([30, 40, 50, 60, 70] as $floor) {
    printf("  >= £%-3d : %d\n", $floor, $atx->filter(fn ($c) => (float) $c->price >= $floor)->count());
}

echo "\n=== Cooler: brand families present (name only evidence) ===\n";
$brands = ['Noctua', 'Arctic', 'be quiet', 'Thermalright', 'Cooler Master', 'Corsair', 'NZXT', 'Asus', 'MSI'];
foreach ($brands as $b) {
    $n = DB::table('components')->where('category_id', $cats['Cooler'])->where('active', 1)
        ->where('name', 'ilike', "%{$b}%")->whereNotNull('price')->count();
    printf("  %-16s %d\n", $b, $n);
}
