<?php
/**
 * Verify the modern gate against PRODUCTION (Neon Postgres), not local SQLite.
 *
 * Rule 7: local SQLite is not production Postgres. Three production-only defects
 * got through a passing SQLite suite before, and the catalogue numbers that
 * drive a customer-visible filter must be checked on the real database before
 * the gate is deployed.
 *
 * Read-only. Selects only.
 */
$root = dirname(__DIR__);

// Load the Neon credentials without disturbing the local .env.
$env = [];
foreach (file($root . '/.env.production.neon', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$host = $env['DB_HOST'] ?? '';
$port = $env['DB_PORT'] ?? '5432';
$db   = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';
$ssl  = ($env['DB_SSLMODE'] ?? 'require');

if ($host === '' || $user === '') {
    fwrite(STDERR, "No Neon credentials in .env.production.neon (host='$host' user='$user')\n");
    exit(2);
}

echo "connecting to Neon: $host:$port/$db as $user\n";

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$db;sslmode=$ssl",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'CONNECT FAILED: ' . $e->getMessage() . "\n");
    exit(3);
}

echo "connected\n\n";

/** Run the SAME derivation logic the shipped gate uses. */
require $root . '/app/Services/CatalogueGate.php';
use App\Services\CatalogueGate;

$cats = $pdo->query("SELECT slug FROM categories")->fetchAll(PDO::FETCH_COLUMN);
echo 'categories: ' . implode(', ', $cats) . "\n\n";

$rows = $pdo->query("SELECT c.slug cat, comp.id, comp.name, comp.socket, comp.chipset, comp.wattage, comp.specs
                     FROM components comp JOIN categories c ON c.id = comp.category_id
                     WHERE comp.active = true")->fetchAll(PDO::FETCH_ASSOC);

echo 'active components in PRODUCTION: ' . count($rows) . "\n\n";

$stats = [];
$leaks = [];
$byCat = [];
foreach ($rows as $r) {
    $cat = $r['cat'];
    $byCat[$cat] = ($byCat[$cat] ?? 0) + 1;

    $name = (string) $r['name'];
    $specs = json_decode((string) $r['specs'], true);
    $specs = is_array($specs) ? $specs : [];

    $keep = true;
    $why = '';
    if ($cat === 'cpu') {
        $sock = CatalogueGate::cpuSocket($name);
        if (! in_array($sock, CatalogueGate::ALLOWED_SOCKETS, true)) { $keep = false; $why = $sock . ' below floor'; }
        elseif (preg_match('/RYZEN\s*(?:[3579]\s*)?(\d{4})/', strtoupper($name), $m) && (int) $m[1] < 5000) {
            $keep = false; $why = 'Ryzen ' . $m[1] . ' older than 5000';
        } elseif (preg_match('/ATHLON/', strtoupper($name))) { $keep = false; $why = 'Athlon'; }
    } elseif ($cat === 'motherboard') {
        if (CatalogueGate::isEntryChipset($name)) { $keep = false; $why = CatalogueGate::chipset($name) . ' entry'; }
        elseif (! in_array(CatalogueGate::boardSocket($name), CatalogueGate::ALLOWED_SOCKETS, true)) {
            $keep = false; $why = CatalogueGate::boardSocket($name) . ' below floor';
        }
    } elseif ($cat === 'ram') {
        $t = CatalogueGate::ramType($name, $specs);
        if ($t === 'DDR3') { $keep = false; $why = 'DDR3'; }
        elseif ($t === CatalogueGate::UNKNOWN) { $keep = false; $why = 'type unknown'; }
    } elseif ($cat === 'psu') {
        $w = $r['wattage'] === null ? null : (int) $r['wattage'];
        if ($w === null) { $keep = false; $why = 'wattage null'; }
        elseif ($w < 650) { $keep = false; $why = $w . 'W below 650W'; }
    }

    if ($keep) { $stats[$cat] = ($stats[$cat] ?? 0) + 1; }
    else { $stats[$cat . ':HIDDEN'] = ($stats[$cat . ':HIDDEN'] ?? 0) + 1; $leaks[$why] = ($leaks[$why] ?? 0) + 1; }
}

echo "########## production survival per category ##########\n";
foreach ($byCat as $cat => $total) {
    $kept = $stats[$cat] ?? 0;
    printf("  %-12s %5d active -> %5d kept (%d hidden)%s", $cat, $total, $kept, $total - $kept, "\n");
    if ($kept === 0 && in_array($cat, ['cpu', 'motherboard', 'ram', 'storage', 'psu'], true)) {
        echo "      *** WOULD BE EMPTY IN PRODUCTION - DO NOT DEPLOY ***\n";
    }
}

echo "\n########## the UNKNOWN-platform rows ##########\n";
$sql = "SELECT c.slug cat, comp.name FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=true AND (c.slug='cpu' OR c.slug='motherboard')";
foreach ($pdo->query($sql) as $r) {
    $name = (string) $r['name'];
    $sock = $r['cat'] === 'cpu' ? CatalogueGate::cpuSocket($name) : CatalogueGate::boardSocket($name);
    if ($sock !== CatalogueGate::UNKNOWN) continue;
    if ($r['cat'] === 'motherboard' && CatalogueGate::isEntryChipset($name)) continue;
    echo '  [' . $r['cat'] . '] ' . $name . PHP_EOL;
}

echo "\n########## what the gate hides, by reason ##########\n";
arsort($leaks);
foreach ($leaks as $why => $n) echo '  ' . str_pad((string) $n, 6) . $why . "\n";