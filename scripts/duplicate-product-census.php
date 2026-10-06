<?php

/**
 * Duplicate-product census. READ-ONLY. Deletes and updates nothing.
 *
 * THE DEFECT THIS FINDS
 * ---------------------
 * Grouping GPUs by the identity that actually matters - name + chipset + memory -
 * collapses 306 rows into 280 groups. 258 rows are cleanly unique. The remaining
 * 22 groups hold 48 rows where the SAME physical product appears more than once,
 * distinguished only by a random hash suffix on the slug:
 *
 *     asus-rog-astral-oc-9798ff     and    asus-rog-astral-oc-c13f50
 *
 * Both carry identical name, chipset, memory and stock. Most carry DIFFERENT
 * PRICES. A storefront showing one graphics card at two prices is a customer-
 * visible accuracy problem and a live risk to a 5-star reputation: a shopper can
 * be quoted one figure and charged another.
 *
 * WHY NOT JUST DELETE THE HIGHER-PRICED ROW
 * -----------------------------------------
 * Because I cannot tell which price is the wrong one. Auto-picking the lower
 * risks under-pricing real hardware; auto-picking the higher risks overcharging.
 * Project rule 2: no destructive write from a single-sample conclusion.
 *
 * So this reports only, and sorts the pairs into:
 *   MERGEABLE   both rows agree on price - merging loses nothing
 *   NEEDS CHECK prices differ - resolve against source_url before touching it
 *
 * Usage:
 *   php scripts/duplicate-product-census.php               # local sqlite
 *   php scripts/duplicate-product-census.php --production  # the truth
 *   php scripts/duplicate-product-census.php --production --category=case
 */

$production = in_array('--production', $argv, true);
$onlyCat = null;
foreach ($argv as $a) {
    if (preg_match('/^--category=(\w+)$/', $a, $m)) {
        $onlyCat = $m[1];
    }
}

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
    $label = 'PRODUCTION Neon';
} else {
    $db = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $label = 'local sqlite';
}

// `active = true`, not `= 1`: that column is a boolean on Postgres and `= 1`
// throws SQLSTATE 42883 there while working fine in SQLite.
$sql = 'SELECT comp.id, comp.name, comp.slug, comp.price, comp.stock,
               comp.source_url, comp.image_url, comp.specs,
               COALESCE(man.name, ?) AS manufacturer, cat.slug AS category
          FROM components comp
          JOIN categories cat ON cat.id = comp.category_id
          LEFT JOIN manufacturers man ON man.id = comp.manufacturer_id
         WHERE comp.active = true';
$args = ['(none)'];
if ($onlyCat !== null) {
    $sql .= ' AND cat.slug = ?';
    $args[] = $onlyCat;
}
$sql .= ' ORDER BY cat.slug, comp.name';

$stmt = $db->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Identity key.
 *
 * FIRST ATTEMPT WAS WRONG and produced a false positive at scale. It keyed on
 * name + chipset + memory and reported "691 redundant rows". But cases and
 * coolers carry neither a chipset nor a memory field, so the key collapsed to the
 * bare display name and merged genuinely distinct products:
 *
 *     asus prime ap201  x4  -> AP201WHTMESH / PRIME-AP201-TG-WH /
 *                               AP201BLKMESH / PRIME-AP201-TG-BK
 *     corsair 2500x     x2  -> CC-9011266-WW vs CC-9011265-WW
 *
 * Four real colourways and two real model numbers, with correctly different
 * prices. Those differences are the catalogue working, not failing.
 *
 * The reliable identity is already stored on every row: PCPartPicker puts a
 * short opaque product ID in the source_url path.
 *
 *     .../product/MFNYcf/antec-c5-argb-atx-mid-tower-case-...
 *              ^^^^^^^ this is the key
 *
 * Two rows sharing a PCPartPicker product ID are the same product listed twice.
 * Two rows with different IDs are different products, however similar their
 * truncated display names look. The descriptive slug segment is kept as a
 * secondary key because it also carries model numbers (CC-9011266-WW).
 */
$key = static function (array $r): string {
    // PCPartPicker product IDs are CASE-SENSITIVE. A second attempt used
    // strtoupper() on them and produced nonsense: it merged an Antec case with an
    // ASUS motherboard because the IDs were `MFNYcf` and `MfNYcf`, which are
    // DIFFERENT products that merely collide once folded. 26 phantom duplicate
    // groups came from that one normalise call.
    //
    // So: use the ID exactly as written. No case folding, ever.
    if (!empty($r['source_url']) && preg_match('#pcpartpicker\.com/product/([A-Za-z0-9]+)#', (string) $r['source_url'], $m)) {
        return 'pcpp:' . $m[1];
    }
    if (!empty($r['source_url']) && preg_match('#pcpartpicker\.com/product/[A-Za-z0-9]+/([^/?#]+)#', (string) $r['source_url'], $m)) {
        return 'pcppdesc:' . $m[1];
    }
    $d = json_decode((string) ($r['specs'] ?? ''), true);
    $d = is_array($d) ? $d : [];
    // Fallback must ALSO keep case: product names carry meaningful capitals
    // (Qube 540 vs QUBE, 2500X vs 2500x) and folding them merges real variants.
    return 'fallback:' . trim((string) $r['name'])
        . '|' . trim((string) ($d['chipset'] ?? ''))
        . '|' . trim((string) ($d['memory'] ?? ''));
};

$groups = [];
foreach ($rows as $r) {
    if ($onlyCat !== null && $r['category'] !== $onlyCat) {
        continue;
    }
    $groups[$key($r)][] = $r;
}

$dups = array_filter($groups, static fn ($g) => count($g) > 1);

$mergeable = [];
$needsCheck = [];
foreach ($dups as $k => $g) {
    $prices = array_unique(array_map(static fn ($r) => (string) ($r['price'] ?? ''), $g));
    if (count($prices) === 1) {
        $mergeable[$k] = $g;
    } else {
        $needsCheck[$k] = $g;
    }
}

$dupRows = 0;
foreach ($dups as $g) {
    $dupRows += count($g);
}
$excess = 0;
foreach ($dups as $g) {
    $excess += count($g) - 1;
}

echo "=== duplicate-product census ({$label}" . ($onlyCat ? ", category {$onlyCat}" : '') . ") ===\n\n";
printf("  rows scanned                        : %d\n", count($rows));
printf("  distinct identity groups           : %d\n", count($groups));
printf("  groups with more than one row      : %d\n", count($dups));
printf("  rows involved                      : %d\n", $dupRows);
printf("  redundant rows if all merged       : %d\n\n", $excess);

printf("  MERGEABLE  (rows agree on price)   : %d group(s), %d row(s)\n", count($mergeable), array_sum(array_map('count', $mergeable)));
printf("  NEEDS CHECK (prices differ)        : %d group(s), %d row(s)\n\n", count($needsCheck), array_sum(array_map('count', $needsCheck)));

if ($needsCheck !== []) {
    echo "=== NEEDS CHECK: the same product at two different prices ===\n";
    echo "    NOT auto-resolvable. One price is wrong and nothing here can say which.\n\n";
    foreach ($needsCheck as $k => $g) {
        printf("  %s\n", $k);
        foreach ($g as $r) {
            printf(
                "    id=%-5d price=%-9s stock=%-5s slug=%s\n",
                $r['id'],
                $r['price'] === null ? 'NULL' : $r['price'],
                $r['stock'] === null ? 'NULL' : $r['stock'],
                $r['slug']
            );
            printf("      source: %s\n", ($r['source_url'] ?: '(none recorded)'));
        }
        echo "\n";
    }
}

if ($mergeable !== []) {
    echo "=== MERGEABLE: rows agree on price, merging loses nothing ===\n\n";
    foreach ($mergeable as $k => $g) {
        printf("  %s\n", $k);
        foreach ($g as $r) {
            printf(
                "    id=%-5d price=%-9s stock=%-5s slug=%s\n",
                $r['id'],
                $r['price'] === null ? 'NULL' : $r['price'],
                $r['stock'] === null ? 'NULL' : $r['stock'],
                $r['slug']
            );
        }
        echo "\n";
    }
}

echo "=== this script changed nothing ===\n";
echo "  It is a report. Merging is a separate, reviewed step, because deciding\n";
echo "  which of two prices is correct is a sourcing question, not a code one.\n";
if (!$production) {
    echo "\nNOTE: LOCAL sqlite. Re-run with --production before acting.\n";
}