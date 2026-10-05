<?php
/**
 * Physical-dimension coverage census. READ-ONLY.
 *
 * WHY THIS EXISTS
 * ---------------
 * App\Services\PartDimensions::resolve() reads per-part dimension fields and falls
 * back to HARDCODED DEFAULTS when they are absent:
 *
 *     'max_gpu'    => $specs['max_gpu_length']        ?? max(320, (int) $d - 80)
 *     'max_cooler' => $specs['max_cpu_cooler_height'] ?? 170
 *     $length      => (int) ($specs['length'] ?? 300)
 *
 * Those defaults are not neutral. A 5080-class card is around 360mm, a 4090
 * around 336mm. Rendered at the 300mm default the card is simply wrong, and for a
 * compact case whose depth resolves to 400mm, max_gpu_length becomes
 * max(320, 320) = 320mm - so a 359mm card is reported as FITTING when it will not
 * physically go in.
 *
 * That is a customer promise failing open on a guess. So measure the real coverage
 * before deciding where dimension data comes from.
 *
 * Usage:
 *   php scripts/dimension-census.php                  # local SQLite
 *   php scripts/dimension-census.php --production     # production Neon (the truth)
 *
 * Project rule 7: local SQLite is NOT proof. Three production-only defects passed
 * a green SQLite suite in this project. Treat --production as the real number.
 */

$production = in_array('--production', $argv, true);

$db = null;
$label = 'local SQLite';

if ($production) {
    // .env.vercel.prod is a TEMPLATE: every value in it is zero-length, so it looks
    // like the keys are present but there is nothing to connect with. The real
    // production credentials live in .env.production.neon. Try that first.
    $candidates = ['.env.production.neon', '.env.vercel.prod'];
    $envFile = null;
    foreach ($candidates as $c) {
        $p = __DIR__ . '/../' . $c;
        if (!is_file($p)) {
            continue;
        }
        foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
        if (!empty($env['DB_HOST']) && !empty($env['DB_PASSWORD'])) {
            $envFile = $c;
            break;
        }
        $env = [];
    }
    if ($envFile === null) {
        fwrite(STDERR, "no usable production credentials. Checked: " . implode(', ', $candidates) . "\n");
        exit(1);
    }
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $req) {
        if (empty($env[$req])) {
            fwrite(STDERR, "missing {$req} (from {$envFile})\n");
            exit(1);
        }
    }
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        $env['DB_HOST'],
        $env['DB_PORT'] ?? '5432',
        $env['DB_DATABASE'],
        $env['DB_SSLMODE'] ?? 'require'
    );
    try {
        // Password passed as the constructor argument, never inside the DSN, so it
        // cannot leak into an exception message.
        $db = new PDO($dsn, $env['DB_USERNAME'], $env['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 20,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "cannot connect to production Neon: " . $e->getMessage() . "\n");
        exit(1);
    }
    $label = 'PRODUCTION Neon';
} else {
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $e) {
        fwrite(STDERR, "cannot open local sqlite: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "=== physical-dimension coverage ({$label}) ===\n";

// Which keys the geometry actually reads. Kept in step with PartDimensions::resolve().
$need = [
    'gpu' => ['length', 'height', 'slot_width', 'tdp'],
    'cpu' => ['tdp', 'socket'],
    'cooler' => ['height', 'radiator_length'],
    'case' => ['width', 'height', 'depth', 'form_factor', 'max_gpu_length', 'max_cpu_cooler_height'],
    'motherboard' => ['form_factor', 'socket'],
    'ram' => ['type', 'height'],
    'psu' => ['length', 'wattage'],
    'storage' => ['length', 'capacity', 'interface'],
];

$sql = 'SELECT c.slug AS cat, comp.name AS nm, comp.specs AS sp
          FROM components comp JOIN categories c ON c.id = comp.category_id
         WHERE comp.active = true';
if ($production) {
    $t = (int) $db->query('SELECT COUNT(*) FROM components')->fetchColumn();
    $a = (int) $db->query('SELECT COUNT(*) FROM components WHERE active = true')->fetchColumn();
    echo "  components: {$t} total, {$a} active\n\n";
}

$all = [];
foreach ($db->query($sql) as $r) {
    $cat = (string) $r['cat'];
    if (!isset($need[$cat])) {
        continue;
    }
    $specs = [];
    if (is_string($r['sp']) && $r['sp'] !== '') {
        $d = json_decode($r['sp'], true);
        if (is_array($d)) {
            $specs = $d;
        }
    }
    $all[$cat][] = ['name' => (string) $r['nm'], 'specs' => $specs];
}

$width = 12;
printf('%-' . $width . 's %7s', 'category', 'rows');
foreach ($need as $keys) {
    foreach ($keys as $k) {
        printf(" %10s", $k);
    }
}
echo "\n" . str_repeat('-', $width + 7 + 10 * 3) . "\n";

$missing = [];
foreach ($need as $cat => $keys) {
    $rows = $all[$cat] ?? [];
    printf('%-' . $width . 's %7d', $cat, count($rows));
    foreach ($keys as $k) {
        $have = 0;
        foreach ($rows as $row) {
            $v = $row['specs'][$k] ?? null;
            // Present-but-empty feeds the same default as absent, so it counts as missing.
            if ($v !== null && $v !== '' && $v !== []) {
                $have++;
            }
        }
        $missing[$cat][$k] = ['have' => $have, 'total' => count($rows)];
        $pct = $rows ? (int) round(100 * $have / count($rows)) : 0;
        printf(" %7d%%%3s", $pct, $pct === 100 ? 'ok' : ($pct >= 80 ? '~' : '!'));
    }
    echo "\n";
}

echo "\n=== the fields that decide whether a build physically fits ===\n";
$critical = [
    ['gpu', 'length'],
    ['case', 'max_gpu_length'],
    ['case', 'max_cpu_cooler_height'],
    ['cooler', 'height'],
    ['case', 'form_factor'],
];
foreach ($critical as [$cat, $key]) {
    $m = $missing[$cat][$key] ?? ['have' => 0, 'total' => 0];
    $miss = $m['total'] - $m['have'];
    $pct = $m['total'] ? (int) round(100 * $miss / $m['total']) : 0;
    printf(
        "  %-11s %-24s %6d of %6d missing (%3d%%)%s\n",
        $cat,
        $key,
        $miss,
        $m['total'],
        $pct,
        $pct > 0 ? '  <- hardcoded default' : ''
    );
}

echo "\n=== sample GPU rows with no length ===\n";
$shown = 0;
foreach ($all['gpu'] ?? [] as $row) {
    if (empty($row['specs']['length']) && $shown < 10) {
        echo '  ' . $row['name'] . "\n";
        $shown++;
    }
}
if ($shown === 0) {
    echo "  (none)\n";
}

echo "\n";
if (!$production) {
    echo "NOTE: LOCAL SQLite. Per project rule 7 this is not proof - re-run with\n";
    echo "      --production before acting on these percentages.\n";
}
