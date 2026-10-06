<?php
// Genie 2026-09-29: a name/column contradiction surfaced in the band probe.
// Querying CPUs WHERE socket='AM5' returned an AMD Ryzen 7 3700X, which is an
// AM4 part. Either the socket column is wrong on some rows, or the name is.
//
// This decides whether the CPU picker needs a generation guard, or whether the
// DATA needs repairing. Those are very different fixes and only one is safe to
// make blind, so measure every row: check each AM5-labelled CPU against the
// chipset implied by its name and report the disagreements.
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

/**
 * The generation implied by the NAME.
 *
 * FIRST ATTEMPT WAS WRONG and said 143/160 rows disagreed with the socket
 * column, including "Ryzen 7 7800X3D is AM4". That is false - 7800X3D is AM5.
 * The bug: any 7xxx number was treated as the AM4 5800X/5700X family. The 7xxx
 * range genuinely straddles two sockets (5700X=AM4, 7700X=AM5) and cannot be
 * split on the number alone. Only the two unambiguous prefixes are asserted:
 *   1xxx / 3xxx / 5xxx          -> AM4
 *   6xxx (6000/6100/6200/6300)  -> AM5
 *   7xxx / 8xxx / 9xxx          -> AM5   (7xxx on AM5 means 7600/7700/7800)
 *   8xxx                        -> AM5   (7800X, 7900, 7950, 9800)
 * Intel i3/i5/i7/i9 digit groups are matched as "digit(3 or 4)" and are NOT
 * mapped to an AMD socket at all - they are classified separately below.
 * Anything not confidently identified returns null and is EXCLUDED from the
 * mismatch count rather than assumed correct.
 */
function nameSocket(string $name): ?string
{
    $n = strtoupper($name);

    // Intel: i3-12100F / i5-12400F / i7-14700K / i9-14900K => LGA1700.
    // Core Ultra 200S => LGA1851.
    if (preg_match('/\bI[3579][- ]?(1[0-9]{4})/', $n)) {
        return 'LGA1700';
    }
    if (preg_match('/\bI[3579][- ]?(1[0-9]{4})/', $n) && str_starts_with($n, 'I')) {
        return 'LGA1700';
    }
    if (preg_match('/ULTRA\s*200S|\b2[56]5[0-9]{2}[A-Z]{2}/', $n)) {
        return 'LGA1851';
    }

    // AMD Zen/Zen+ AM4 families: 1000 (Zen), 3000, 5000.
    if (preg_match('/\b(1|3|5)[0-9]{3}[A-Z0-9]*\b/', $n)) {
        return 'AM4';
    }

    // AM5: 6000-series, and every 7xxx/8xxx/9xxx Zen 4/5 part.
    if (preg_match('/\b[6-9][0-9]{3}[A-Z0-9]*\b/', $n)) {
        return 'AM5';
    }

    return null;
}

$cpus = DB::table('components')->where('category_id', 1)->where('active', 1)->whereNotNull('price')->get();

echo "PRODUCTION " . DB::connection()->getDriverName() . "   CPUs priced: {$cpus->count()}\n\n";

$bySocket = $cpus->groupBy('socket');
echo "=== rows per socket column ===\n";
foreach ($bySocket as $s => $rows) {
    printf("  %-10s %d\n", $s === '' ? '(null)' : $s, $rows->count());
}

$mismatch = [];
$unknown = 0;
$agree = 0;
foreach ($cpus as $c) {
    $implied = nameSocket((string) $c->name);
    if ($implied === null) {
        $unknown++;
        continue;
    }
    $col = (string) ($c->socket ?? '');
    if (strtoupper($col) === $implied) {
        $agree++;
    } else {
        $mismatch[] = [$c->id, $c->name, $col, $implied, $c->price];
    }
}

printf("\n  name-confident: %d   agree: %d   MISMATCH: %d   unclassifiable-by-name: %d\n",
    $agree + count($mismatch), $agree, count($mismatch), $unknown);

printf("\n=== ALL %d SOCKET MISMATCHES ===\n", count($mismatch));
foreach (array_slice($mismatch, 0, 40) as [$id, $name, $col, $implied, $price]) {
    printf("  id=%-6d col=%-9s name-says=%-9s £%-8.2f %s\n", $id, $col ?: '-', $implied, $price, substr($name, 0, 44));
}
if (count($mismatch) > 40) {
    printf("  ... and %d more\n", count($mismatch) - 40);
}

// Restrict to AMD-only comparisons. The Intel LGA1200 vs LGA1700 distinction
// needs a 400-vs-1700 socket test, which this name classifier deliberately does
// not attempt, so Intel rows are reported separately and not called defects.
$amdMismatch = array_filter($mismatch, fn ($m) => str_contains(strtoupper($m[1]), 'RYZEN') || str_contains(strtoupper($m[1]), 'AMD'));
$intelUnclear = array_filter($mismatch, fn ($m) => str_contains(strtoupper($m[1]), 'INTEL'));
printf("\n  AMD mismatches (verifiable defect): %d\n", count($amdMismatch));
printf("  Intel mismatches (LGA1200/1700 not separated by this probe - NOT a defect claim): %d\n\n", count($intelUnclear));

// Direction matters: a CPU labelled AM5 that is really AM4 is dangerous (it gets
// paired with an AM5 board). The reverse is merely unsellable.
$dangerous = array_filter($amdMismatch, fn ($m) => strtoupper($m[2]) === 'AM5' && $m[3] === 'AM4');
printf("  DANGEROUS (col=AM5, name=AM4 - would be seated in an AM5 board): %d\n", count($dangerous));
foreach ($dangerous as [$id, $name, , , $price]) {
    printf("    id=%-6d £%-8.2f %s\n", $id, $price, substr($name, 0, 50));
}
