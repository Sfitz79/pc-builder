<?php
/**
 * Read-only probe: where does the missing metadata actually live?
 *
 * Rule 1 in AGENTS.md: measure data quality through the code path that will USE
 * it, against every field it could read. The `socket` column is null for 100
 * CPUs and `chipset` is null for all 400 motherboards - but a null column does
 * NOT mean absent data until specs JSON has been read. This dumps the spec keys
 * and values so the grouping keys are chosen from evidence.
 */
$db = new PDO('sqlite:database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function specKeys(string $cat): array {
    global $db;
    $keys = [];
    $n = 0;
    $sql = "SELECT comp.specs, comp.socket, comp.chipset FROM components comp
            JOIN categories c ON c.id = comp.category_id
            WHERE comp.active = 1 AND c.slug = ?";
    $st = $db->prepare($sql);
    $st->execute([$cat]);
    foreach ($st as $r) {
        $n++;
        $s = json_decode((string) $r['specs'], true);
        if (!is_array($s)) continue;
        foreach ($s as $k => $v) {
            if (!is_scalar($v)) continue;
            $keys[(string) $k] = ($keys[(string) $k] ?? 0) + 1;
        }
    }
    arsort($keys);
    echo "--- $cat: $n active rows ---" . PHP_EOL;
    $i = 0;
    foreach ($keys as $k => $c) {
        echo str_pad($k, 26) . $c . PHP_EOL;
        if (++$i >= 18) break;
    }
    echo PHP_EOL;
    return $keys;
}

echo "########## SPEC KEY COVERAGE ##########" . PHP_EOL;
foreach (['cpu', 'motherboard', 'ram', 'storage', 'psu'] as $cat) specKeys($cat);

echo "########## CPU: the 100 with NULL socket ##########" . PHP_EOL;
$sql = "SELECT comp.name, comp.socket, comp.specs FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'cpu' AND comp.socket IS NULL LIMIT 12";
foreach ($db->query($sql) as $r) {
    echo '  ' . str_pad((string) $r['name'], 46) . ' socket=' . var_export($r['socket'], true) . PHP_EOL;
}

echo PHP_EOL . "########## CPU names grouped by socket value ##########" . PHP_EOL;
$sql = "SELECT COALESCE(comp.socket,'(NULL)') s, comp.name FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'cpu' ORDER BY s, comp.name";
$bucket = [];
foreach ($db->query($sql) as $r) $bucket[$r['s']][] = $r['name'];
foreach ($bucket as $socket => $names) {
    echo PHP_EOL . '### socket=' . $socket . '  (' . count($names) . ')' . PHP_EOL;
    foreach (array_slice($names, 0, 14) as $n) echo '   ' . $n . PHP_EOL;
    if (count($names) > 14) echo '   ... +' . (count($names) - 14) . ' more' . PHP_EOL;
}

echo PHP_EOL . "########## MOTHERBOARD names grouped by socket ##########" . PHP_EOL;
$sql = "SELECT COALESCE(comp.socket,'(NULL)') s, comp.name, comp.chipset FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'motherboard' ORDER BY s, comp.name LIMIT 60";
foreach ($db->query($sql) as $r) {
    echo '  ' . str_pad((string) $r['s'], 10) . str_pad(mb_substr((string) $r['name'], 0, 44), 46)
        . ' chipset=' . var_export($r['chipset'], true) . PHP_EOL;
}