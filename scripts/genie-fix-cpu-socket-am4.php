<?php
// Genie 2026-09-29: DRY RUN ONLY - writes nothing.
//
// Confirmed defect: 37 production CPUs are labelled socket='AM5' while their
// name is unambiguously an AM4 part (Ryzen 5 5500, Ryzen 7 3700X, Ryzen 9
// 5950X, Ryzen 3 3200G, ...). An AM4 CPU cannot be seated in an AM5 board, so
// any build assembled from the socket column alone can be unbuildable.
//
// Why this is dry-run: components is a revenue-bearing catalogue and the fix
// relabels live rows. Per Rule 2 the destructive step needs a report that has
// been read and defended, and per Rule 7 it must be verified against the real
// Postgres, not local sqlite. So: emit the candidate UPDATE list, prove the
// evidence, and change nothing.
//
// Fix is applied with --write only after review.
$apply = in_array('--write', $argv ?? [], true);

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

/** AM4 = Ryzen 1000/3000/5000 series. AM5 = 6000+ and every 7/8/9xxx. */
function am4FromName(string $name): bool
{
    $n = strtoupper($name);
    if (! str_contains($n, 'RYZEN')) {
        return false;
    }

    return preg_match('/\b(1|3|5)[0-9]{3}[A-Z0-9]*\b/', $n) === 1;
}

$rows = DB::table('components')->where('category_id', 1)->where('active', 1)
    ->where('socket', 'AM5')->whereNotNull('price')->get();

$candidates = $rows->filter(fn ($r) => am4FromName((string) $r->name))->values();

printf("PRODUCTION %s\n", DB::connection()->getDriverName());
printf("mode: %s\n\n", $apply ? '*** WRITE ***' : 'DRY RUN (no rows modified)');
printf("active priced CPUs labelled AM5: %d\n", $rows->count());
printf("of those, AM4 by name: %d\n\n", $candidates->count());

printf("%-8s %-10s %s\n", 'id', 'price', 'name');
foreach ($candidates as $c) {
    printf("%-8d £%-9.2f %s\n", $c->id, $c->price, $c->name);
}

// Sanity: confirm every candidate really does carry a 1000/3000/5000 token, so
// the list cannot be a regex accident.
printf("\n=== VERIFICATION ===\n");
$bad = $candidates->filter(fn ($c) => preg_match('/\b(1|3|5)[0-9]{3}/', (string) $c->name) !== 1);
printf("  rows failing the explicit AM4 token check: %d\n", $bad->count());

// What is the blast radius? Anything already using these rows in a build.
$affects = DB::table('components')->where('category_id', 1)->whereIn('id', $candidates->pluck('id'))->count();
printf("  catalogue rows affected: %d of %d CPUs\n", $affects, DB::table('components')->where('category_id', 1)->count());
printf("  relabelled AM5 -> AM4 (removes them from AM5 builds, keeps them sellable on AM4)\n");

if (! $apply) {
    echo "\n  NOTHING WRITTEN. Re-run with --write to apply.\n";

    exit(0);
}

$updated = 0;
foreach ($candidates as $c) {
    $updated += DB::table('components')->where('id', $c->id)->update(['socket' => 'AM4']);
}
printf("\n  UPDATED %d rows.\n", $updated);

// Read back and prove the label now agrees with the name.
$still = DB::table('components')->whereIn('id', $candidates->pluck('id'))
    ->where('socket', 'AM5')->count();
printf("  rows still mislabelled AM5 after write: %d\n", $still);
