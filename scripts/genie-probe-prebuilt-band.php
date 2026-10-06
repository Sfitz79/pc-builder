<?php
// Genie 2026-09-29: the picker is now quality-gated, and 2 of 3 tiers FAIL their
// budget band. Before moving a band I need to know WHY, because the band is a
// promise and the parts are now honest. A band that fails because the parts are
// wrong should be fixed in the parts; a band that fails because the market moved
// should be moved deliberately and documented.
//
// This attributes the overshoot to individual line items and asks whether each
// line is genuinely wrong (mis-picked) or merely expensive.
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
$cats = DB::table('categories')->pluck('id', 'name');

echo "PRODUCTION " . DB::connection()->getDriverName() . "\n\n";

echo "=== RAM: is £373 for 32GB an outlier or the floor? ===\n";
$ram = DB::table('components')->where('category_id', $cats['RAM'])->where('active', 1)->whereNotNull('price')->get();
foreach ([16, 32, 64] as $gb) {
    $sub = $ram->filter(function ($r) use ($gb) {
        return (int) preg_replace('/[^0-9]/', '', (string) (specsOf($r)['capacity'] ?? '')) === $gb;
    })->map(fn ($r) => (float) $r->price)->sort()->values();
    if ($sub->isEmpty()) {
        continue;
    }
    printf("  %2dGB  n=%-4d min=£%-8.2f p10=£%-8.2f median=£%-8.2f p90=£%-8.2f max=£%.2f\n",
        $gb, $sub->count(), $sub->min(), $sub->get((int) ($sub->count() * 0.1)),
        $sub->get((int) ($sub->count() * 0.5)), $sub->get((int) ($sub->count() * 0.9)), $sub->max());
}

echo "\n  cheapest 5 x 32GB kits:\n";
foreach ($ram->filter(function ($r) {
    return (int) preg_replace('/[^0-9]/', '', (string) (specsOf($r)['capacity'] ?? '')) === 32;
})->sortBy('price')->take(5) as $r) {
    printf("    £%-8.2f  %-52s speed=%s type=%s\n", $r->price, substr($r->name, 0, 52),
        specsOf($r)['speed'] ?? '?', specsOf($r)['type'] ?? '?');
}

echo "\n=== AM5 CPU: the Starter pick was >= 28% of £700 = £196 ===\n";
$cpu = DB::table('components')->where('category_id', $cats['CPU'])->where('active', 1)
    ->where('socket', 'AM5')->whereNotNull('price')->where('price', '>=', 196)
    ->get()->filter(fn ($c) => specsOf($c)['cores'] ?? null)->sortBy('price');
foreach ($cpu->take(6) as $c) {
    printf("    £%-8.2f  %-46s cores=%-3d threads=%-3d wattage=%s\n", $c->price,
        substr($c->name, 0, 46), specsOf($c)['cores'], specsOf($c)['threads'], $c->wattage ?? 'n/a');
}

echo "\n=== PSU: £65 for the whole build. Is a 650W 'Thermaltake Smart' real? ===\n";
$psu = DB::table('components')->where('category_id', $cats['PSU'])->where('active', 1)
    ->whereNotNull('price')->whereNotNull('wattage')->get();
printf("  priced+watts rows: %d   wattage range: %dW .. %dW\n", $psu->count(),
    $psu->min('wattage'), $psu->max('wattage'));
echo "  cheapest 6 by price:\n";
foreach ($psu->sortBy('price')->take(6) as $p) {
    printf("    £%-8.2f  %-46s %sW\n", $p->price, substr($p->name, 0, 46), $p->wattage);
}
$at650 = $psu->filter(fn ($p) => (int) $p->wattage >= 600)->map(fn ($p) => (float) $p->price)->sort()->values();
printf("  >=600W: n=%d min=£%.2f median=£%.2f\n", $at650->count(), $at650->min(), $at650->get((int) ($at650->count() / 2)));

echo "\n=== Storage >=1TB: what class is the cheapest? ===\n";
$sto = DB::table('components')->where('category_id', $cats['Storage'])->where('active', 1)->whereNotNull('price')->get()
    ->filter(fn ($s) => (int) preg_replace('/[^0-9]/', '', (string) (specsOf($s)['capacity'] ?? '')) >= 1000)
    ->sortBy('price');
foreach ($sto->take(5) as $s) {
    $sp = specsOf($s);
    printf("    £%-8.2f  %-48s cap=%-10s type=%-12s read=%s\n", $s->price, substr($s->name, 0, 48),
        $sp['capacity'] ?? '?', $sp['type'] ?? '?', $sp['read_speed'] ?? '?');
}
