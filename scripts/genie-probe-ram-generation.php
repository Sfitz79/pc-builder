<?php
// Genie 2026-09-29: the DDR5 guard I added rejects ALL 32GB kits ("SKIPPED: no
// 32GB RAM") yet production holds 165 priced 32GB rows. So the guard is wrong,
// not the data. Rule 8: find out which, before adjusting either.
//
// The suspect is the fallback: specs.type exists on only 2 RAM rows, so the guard
// leans on a NAME match for /DDR5/. If catalogue names spell the generation
// differently (or omit it entirely) the fallback rejects everything.
//
// This dumps the exact rows the guard would consider, split by why they failed.
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

// Category resolved BY NAME, never by a hardcoded id. I hardcoded 5 and got 0
// rows: production is RAM=4 (local sqlite inserts "CPU Cooler" at id 2 and shifts
// every id by one), so the probe silently measured an empty set and I nearly
// "fixed" a guard that was not broken. Category ids are NOT portable between the
// two databases.
$ramCat = DB::table('categories')->where('name', 'RAM')->value('id');
if (! $ramCat) {
    fwrite(STDERR, "no RAM category\n");
    exit(1);
}
$ram = DB::table('components')->where('category_id', $ramCat)->where('active', 1)->whereNotNull('price')->get();
echo "RAM category_id = {$ramCat}\n";

$cap32 = $ram->filter(function ($r) {
    return (int) preg_replace('/[^0-9]/', '', (string) (specsOf($r)['capacity'] ?? '')) === 32;
});
echo "PRODUCTION " . DB::connection()->getDriverName() . "\n";
echo "32GB priced rows: {$cap32->count()}\n\n";

$withType = $cap32->filter(fn ($r) => (string) (specsOf($r)['type'] ?? '') !== '');
$nameHasDdr5 = $cap32->filter(fn ($r) => preg_match('/DDR5/i', (string) $r->name) === 1);
$nameHasDdr4 = $cap32->filter(fn ($r) => preg_match('/DDR4/i', (string) $r->name) === 1);
$neither = $cap32->filter(fn ($r) => ! preg_match('/DDR[45]/i', (string) $r->name));

printf("  specs.type populated : %d\n", $withType->count());
printf("  name contains DDR5   : %d\n", $nameHasDdr5->count());
printf("  name contains DDR4   : %d\n", $nameHasDdr4->count());
printf("  name states NEITHER  : %d   <-- these all fail the guard\n", $neither->count());

echo "\n=== the two rows that HAVE specs.type ===\n";
foreach ($withType as $r) {
    $s = specsOf($r);
    printf("  £%-8.2f type=%-8s speed=%-12s %s\n", $r->price, $s['type'], $s['speed'] ?? '?', $r->name);
}

echo "\n=== 8 cheapest 32GB with NEITHER in the name (guard rejects these) ===\n";
foreach ($neither->sortBy('price')->take(8) as $r) {
    $s = specsOf($r);
    printf("  £%-8.2f speed=%-14s %s\n", $r->price, $s['speed'] ?? '?', substr($r->name, 0, 56));
}

echo "\n=== 8 cheapest 32GB that DO say DDR5 (guard accepts) ===\n";
foreach ($nameHasDdr5->sortBy('price')->take(8) as $r) {
    $s = specsOf($r);
    printf("  £%-8.2f speed=%-14s %s\n", $r->price, $s['speed'] ?? '?', substr($r->name, 0, 56));
}

echo "\n=== does the SPEED field imply the generation when the name does not? ===\n";
// DDR4 tops out around 3600; DDR5 starts at 4800. Using speed is a REAL signal
// from data, unlike guessing from the name.
$sp = $neither->map(fn ($r) => (int) preg_replace('/[^0-9]/', '', (string) (specsOf($r)['speed'] ?? '')))
    ->filter(fn ($v) => $v > 0)->sort()->values();
if ($sp->isNotEmpty()) {
    printf("  speed range on name-silent rows: %d .. %d\n", $sp->min(), $sp->max());
    printf("  rows implying DDR5 (speed>=4000): %d\n", $neither->filter(function ($r) {
        $v = (int) preg_replace('/[^0-9]/', '', (string) (specsOf($r)['speed'] ?? ''));
        return $v >= 4000;
    })->count());
    printf("  rows implying DDR4 (speed<4000) : %d\n", $neither->filter(function ($r) {
        $v = (int) preg_replace('/[^0-9]/', '', (string) (specsOf($r)['speed'] ?? ''));
        return $v > 0 && $v < 4000;
    })->count());
}
