<?php
/** READ-ONLY. Why is RAM not groupable by DDR generation? Dump real names+specs. */
$db = new PDO('sqlite:database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach (['ram', 'storage'] as $cat) {
    echo "########## $cat: name + specs (first 15 active) ##########" . PHP_EOL;
    $sql = "SELECT comp.name, comp.specs, comp.socket, comp.wattage FROM components comp
            JOIN categories c ON c.id = comp.category_id
            WHERE comp.active = 1 AND c.slug = ? ORDER BY comp.name LIMIT 15";
    $st = $db->prepare($sql);
    $st->execute([$cat]);
    foreach ($st as $r) {
        $sp = json_decode((string) $r['specs'], true);
        $short = [];
        if (is_array($sp)) foreach ($sp as $k => $v) if (is_scalar($v)) $short[] = "$k=" . (string) $v;
        echo '  ' . str_pad(mb_substr((string) $r['name'], 0, 48), 50)
            . ' {' . implode(', ', array_slice($short, 0, 5)) . '}' . PHP_EOL;
    }
    echo PHP_EOL;
}

echo "########## RAM: does any active row mention DDR at all? ##########" . PHP_EOL;
$sql = "SELECT comp.name, comp.specs FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'ram'";
$hit = 0; $total = 0; $samples = [];
foreach ($db->query($sql) as $r) {
    $total++;
    $blob = strtoupper((string) $r['name'] . ' ' . (string) $r['specs']);
    if (str_contains($blob, 'DDR')) {
        $hit++;
        if (count($samples) < 8) $samples[] = '  ' . $r['name'] . '   specs=' . $r['specs'];
    }
}
echo "rows containing 'DDR' anywhere: $hit of $total" . PHP_EOL;
foreach ($samples as $s) echo $s . PHP_EOL;

echo PHP_EOL . "########## RAM: are there inactive DDR4 rows we are hiding? ##########" . PHP_EOL;
foreach ([1, 0] as $act) {
    $sql = "SELECT COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id
            WHERE c.slug='ram' AND comp.active = ?";
    $st = $db->prepare($sql); $st->execute([$act]);
    echo '  active=' . $act . ' -> ' . $st->fetch()['n'] . PHP_EOL;
}
$sql = "SELECT comp.name, comp.active, comp.price, comp.specs FROM components comp
        JOIN categories c ON c.id=comp.category_id
        WHERE c.slug='ram' AND (UPPER(comp.name) LIKE '%DDR4%' OR UPPER(comp.name) LIKE '%DDR 4%' OR UPPER(comp.specs) LIKE '%DDR4%')
        LIMIT 10";
foreach ($db->query($sql) as $r) {
    echo '  DDR4 candidate: active=' . $r['active'] . ' price=' . $r['price'] . '  ' . $r['name'] . PHP_EOL;
}

echo PHP_EOL . "########## INACTIVE rows per category (are we hiding stock?) ##########" . PHP_EOL;
foreach ($db->query("SELECT c.slug cat, COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=0 GROUP BY c.slug ORDER BY n DESC") as $r) {
    echo '  ' . str_pad($r['cat'], 14) . $r['n'] . ' inactive' . PHP_EOL;
}

echo PHP_EOL . "########## stock=0 among active ##########" . PHP_EOL;
foreach ($db->query("SELECT c.slug cat, COUNT(*) n FROM components comp JOIN categories c ON c.id=comp.category_id WHERE comp.active=1 AND (comp.stock IS NULL OR comp.stock<=0) GROUP BY c.slug ORDER BY n DESC") as $r) {
    echo '  ' . str_pad($r['cat'], 14) . $r['n'] . PHP_EOL;
}