<?php

/**
 * Apply PUBLISHED-STANDARD dimensions to production components.
 *
 * DRY RUN BY DEFAULT. Nothing is written without --apply, and the dry run prints
 * exactly what would change plus a sample, so the change is reviewed before it
 * reaches the catalogue.
 *
 *   php scripts/apply-standards-dimensions.php                 # dry run (safe)
 *   php scripts/apply-standards-dimensions.php --apply         # write
 *   php scripts/apply-standards-dimensions.php --local         # local sqlite
 *   php scripts/apply-standards-dimensions.php --apply --limit=50
 *
 * Covers motherboard, psu, ram and storage - the categories whose fit-critical
 * dimension is fixed by a published standard. Deliberately does NOT touch gpu,
 * cooler or case: those need the manufacturer's figure for that exact model, and a
 * plausible-looking guess would turn an honest "unverified" into a false pass.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\Dimensions\StandardsIngestor;

$apply = in_array('--apply', $argv, true);
$local = in_array('--local', $argv, true);
$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = (int) $m[1];
    }
}

if (!$local) {
    $envFile = __DIR__ . '/../.env.production.neon';
    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
    $db = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $env['DB_HOST'], $env['DB_PORT'] ?? '5432', $env['DB_DATABASE'], $env['DB_SSLMODE'] ?? 'require'),
        $env['DB_USERNAME'],
        $env['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20]
    );
    $target = 'PRODUCTION Neon';
} else {
    $db = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $target = 'local sqlite';
}

$standards = StandardsIngestor::loadStandards(__DIR__ . '/../resources/dimensions/standards.json');
$ingestor = new StandardsIngestor($db, $standards);
$plan = $ingestor->plan();

$changes = $plan['changes'];
if ($limit > 0) {
    $changes = array_slice($changes, 0, $limit);
}

echo "=== standards dimension ingest ({$target}) ===\n";
echo $apply ? "  MODE: APPLY\n\n" : "  MODE: DRY RUN - nothing will be written\n\n";

$byCat = [];
foreach ($changes as $c) {
    $byCat[$c['category']] = ($byCat[$c['category']] ?? 0) + 1;
}
printf("  components that would change : %d\n", count($changes));
foreach ($byCat as $cat => $n) {
    printf("      %-12s %5d\n", $cat, $n);
}

$skipByCat = [];
$skipWhy = [];
foreach ($plan['skipped'] as $s) {
    $skipByCat[$s['category']] = ($skipByCat[$s['category']] ?? 0) + 1;
    $skipWhy[$s['why']] = ($skipWhy[$s['why']] ?? 0) + 1;
}
printf("\n  components skipped          : %d\n", count($plan['skipped']));
foreach ($skipByCat as $cat => $n) {
    printf("      %-12s %5d\n", $cat, $n);
}
echo "\n  why:\n";
foreach ($skipWhy as $why => $n) {
    printf("      %5d  %s\n", $n, $why);
}

echo "\n=== sample of what would be written ===\n";
$shown = 0;
foreach ($changes as $c) {
    if ($shown >= 10) {
        break;
    }
    echo '  ' . $c['name'] . "  [" . $c['category'] . "]\n";
    foreach ($c['added'] as $k => $v) {
        $shown2 = is_scalar($v) ? var_export($v, true) : json_encode($v);
        echo "      + {$k} = {$shown2}\n";
    }
    $shown++;
}

echo "\n=== deliberately NOT touched ===\n";
foreach (($standards['_varies_per_model'] ?? []) as $cat => $fields) {
    if ($cat === '_why') {
        continue;
    }
    printf("  %-12s %s\n", $cat, implode(', ', (array) $fields));
}
echo "\n  Those have no published standard. They stay unverified rather than guessed.\n";

if (!$apply) {
    echo "\nDRY RUN. Re-run with --apply to write these.\n";
    exit(0);
}

$written = $ingestor->apply($changes);
echo "\nAPPLIED: {$written} component(s) updated.\n";
echo "Re-run: php scripts/dimension-census.php --production   to see the new coverage\n";
echo "        php scripts/verify-fit-verification.php       to confirm the gate still holds\n";
