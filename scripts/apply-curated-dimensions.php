<?php

/**
 * Apply SOURCED per-model dimensions from a curated, provenance-carrying dataset.
 *
 * DRY RUN BY DEFAULT.
 *   php scripts/apply-curated-dimensions.php                      # dry run
 *   php scripts/apply-curated-dimensions.php --apply              # write
 *   php scripts/apply-curated-dimensions.php --production         # against Neon
 *   php scripts/apply-curated-dimensions.php --production --apply
 *
 * Refuses to apply anything it cannot vouch for:
 *   - an entry without a PCPartPicker product ID is rejected
 *   - a value outside plausible physical bounds is rejected as a parse error
 *   - a value that disagrees with a value already in the catalogue is reported as
 *     a CONFLICT and NOT written, because the catalogue may already be right
 *
 * Run scripts/dimension-census.php --production afterwards to watch coverage.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\Dimensions\CuratedDimensions;

$apply = in_array('--apply', $argv, true);
$production = in_array('--production', $argv, true);

if ($production) {
    $env = [];
    foreach (file(__DIR__ . '/../.env.production.neon', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

$path = __DIR__ . '/../resources/dimensions/curated.json';
$dataset = CuratedDimensions::loadDataset($path);
$gate = new CuratedDimensions($db);
$plan = $gate->plan($dataset);

printf("=== curated dimension ingest (%s) ===\n", $target);
printf("  MODE      : %s\n", $apply ? 'APPLY' : 'DRY RUN - nothing written');
printf("  dataset   : %d entries\n", count($dataset['components'] ?? []));
printf("\n  would write : %d\n", $plan['applied']);
printf("  conflicts   : %d  (catalogue already has a DIFFERENT value; not written)\n", count($plan['conflicts']));
printf("  rejected    : %d  (no ID, non-numeric, or outside plausible bounds)\n", count($plan['rejected']));
printf("  unmatched   : %d  (PCPartPicker ID not found in the catalogue)\n", count($plan['unmatched']));

if ($plan['conflicts'] !== []) {
    echo "\n=== CONFLICTS: resolve before applying ===\n";
    foreach ($plan['conflicts'] as $c) {
        printf("  %-42s %-20s catalogue=%s  proposed=%s\n", $c['name'], $c['key'], $c['existing'], $c['proposed']);
    }
}
if ($plan['rejected'] !== []) {
    echo "\n=== REJECTED ===\n";
    foreach ($plan['rejected'] as $r) {
        printf("  %-42s %s\n", $r['entry']['name'] ?? $r['name'] ?? '(unnamed)', $r['why']);
    }
}
if ($plan['unmatched'] !== []) {
    echo "\n=== UNMATCHED (sample) ===\n";
    foreach (array_slice($plan['unmatched'], 0, 12) as $u) {
        printf("  %-42s pcpp=%s\n", $u['name'], $u['pcpp_id']);
    }
    if (count($plan['unmatched']) > 12) {
        printf("  ... and %d more\n", count($plan['unmatched']) - 12);
    }
}

if ($plan['rows'] !== []) {
    echo "\n=== sample of what would be written ===\n";
    foreach (array_slice($plan['rows'], 0, 10) as $r) {
        echo '  ' . $r['name'] . "  (pcpp {$r['pcpp_id']})\n";
        foreach ($r['set'] as $k => $v) {
            printf("      %-22s = %s mm\n", $k, rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));
        }
        printf("      source: %s\n", $r['source'] ?: '(NOT RECORDED)');
        if ($r['retrieved']) {
            printf("      retrieved: %s\n", $r['retrieved']);
        }
    }
}

if (!$apply) {
    echo "\nDRY RUN. Re-run with --apply to write.\n";
    exit(0);
}
if ($plan['conflicts'] !== []) {
    echo "\nNOT APPLIED: conflicts must be resolved first.\n";
    exit(1);
}

$written = $gate->apply($plan['rows']);
echo "\nAPPLIED {$written} component(s).\n";
echo "php scripts/dimension-census.php --production   # coverage after\n";
echo "php scripts/verify-fit-verification.php       # gate still holds\n";