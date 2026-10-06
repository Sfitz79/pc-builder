<?php
/**
 * Catalogue census. Read-only. Measures the REAL distribution of every field a
 * modern gate would filter on, so a gate is built against data rather than
 * against an assumption about what the catalogue contains.
 */
$db = new PDO('sqlite:database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function section(string $title): void {
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

section('active components per category');
foreach ($db->query("SELECT c.slug cat, COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 GROUP BY c.slug ORDER BY n DESC") as $r) {
    echo str_pad($r['cat'], 14) . $r['n'] . PHP_EOL;
}

section('CPUs by socket (active)');
foreach ($db->query("SELECT COALESCE(socket,'(null)') s, COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='cpu' GROUP BY socket ORDER BY n DESC") as $r) {
    echo str_pad((string) $r['s'], 22) . $r['n'] . PHP_EOL;
}

section('MOTHERBOARDS by socket (active)');
foreach ($db->query("SELECT COALESCE(socket,'(null)') s, COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='motherboard' GROUP BY socket ORDER BY n DESC") as $r) {
    echo str_pad((string) $r['s'], 22) . $r['n'] . PHP_EOL;
}

section('MOTHERBOARDS by chipset (active, top 30)');
foreach ($db->query("SELECT COALESCE(chipset,'(null)') s, COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='motherboard' GROUP BY chipset ORDER BY n DESC LIMIT 30") as $r) {
    echo str_pad((string) $r['s'], 26) . $r['n'] . PHP_EOL;
}

section('PSU wattage distribution (active)');
foreach ($db->query("SELECT COALESCE(wattage,'(null)') w, COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='psu' GROUP BY wattage ORDER BY CAST(wattage AS INTEGER)") as $r) {
    echo str_pad((string) $r['w'], 12) . $r['n'] . PHP_EOL;
}

section('PSU: how many are >= 650W?');
$row = $db->query("SELECT COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='psu' AND wattage >= 650")->fetch();
echo '650W or more: ' . $row['n'] . PHP_EOL;
$row = $db->query("SELECT COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='psu' AND (wattage IS NULL OR wattage < 650)")->fetch();
echo 'under 650W or null: ' . $row['n'] . PHP_EOL;

section('RAM: distinct memory-type-ish values inside specs');
$keys = [];
foreach ($db->query("SELECT comp.specs FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='ram'") as $r) {
    $s = json_decode((string) $r['specs'], true);
    if (!is_array($s)) continue;
    foreach ($s as $k => $v) {
        if (preg_match('/(type|memory|ddr|generation)/i', (string) $k)) {
            $keys[$k . '=' . (is_scalar($v) ? (string) $v : '…')] = true;
        }
    }
}
foreach (array_slice(array_keys($keys), 0, 25) as $k) echo $k . PHP_EOL;

section('STORAGE: interface-ish spec values');
$keys = [];
foreach ($db->query("SELECT comp.specs FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='storage'") as $r) {
    $s = json_decode((string) $r['specs'], true);
    if (!is_array($s)) continue;
    foreach ($s as $k => $v) {
        if (preg_match('/(interface|type|capacity|form|conn)/i', (string) $k)) {
            $keys[$k . '=' . (is_scalar($v) ? (string) $v : '…')] = true;
        }
    }
}
foreach (array_slice(array_keys($keys), 0, 30) as $k) echo $k . PHP_EOL;

section('CPU sample names per socket (so series naming can be judged)');
foreach ($db->query("SELECT socket, name FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND c.slug='cpu' ORDER BY socket, name LIMIT 200") as $r) {
    echo str_pad((string) $r['socket'], 14) . $r['name'] . PHP_EOL;
}