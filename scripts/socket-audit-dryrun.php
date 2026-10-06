<?php
/**
 * READ-ONLY DRY RUN. Changes nothing.
 *
 * Derives socket / chipset / memory-type from the component NAME and diffs it
 * against what is stored. The point is to produce a report that can be defended
 * BEFORE any write, because the stored columns cannot currently be trusted:
 *
 *   - socket is NULL for 100 of 237 active CPUs (i7-4790K, Athlon II X2 240e ...)
 *   - chipset is NULL for 397 of 400 active motherboards
 *   - specs.type is NULL for 375 of 377 active RAM
 *   - and worst, some socket values are WRONG: Ryzen 3 3200G / 5 1600 / 5 2600 /
 *     5 3600 are all labelled AM5 when they are AM4 (Zen1/2/3 are AM4).
 *
 * A modern gate built on the stored column would therefore be wrong in both
 * directions: it would wave through AM4 silicon as AM5, and it would have
 * nothing to test at all for the 100 rows with no socket.
 */
$db = new PDO('sqlite:database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Derive a CPU socket from the name. Ordered most-specific first.
 * Ryzen generation -> socket mapping is fixed by AMD and does not change:
 *   1000/2000/3000 (Zen1/2/3) = AM4 ; 7000/9000 (Zen4/5) = AM5 ; 5000 = AM4
 * Intel 11th = LGA1200, 12th/13th/14th = LGA1700, Core Ultra 200 = LGA1851.
 */
function cpuSocketFromName(string $name): ?string {
    $n = strtoupper($name);

    // --- AMD Ryzen ---------------------------------------------------------
    if (preg_match('/RYZEN\s+([3579])\s*(\d{4})/', $n, $m)) {
        $series = (int) $m[1];      // 3/5/7/9
        $num    = (int) $m[2];
        if ($series === 3 && $num < 5000) return 'AM4';   // Athlon + Ryzen 3xxx
        if ($num >= 7000) return 'AM5';                    // Ryzen 7000/9000
        if ($series === 5 || $series === 7) return 'AM4';  // Ryzen 5000
        if ($num >= 1000 && $num < 5000) return 'AM4';     // Ryzen 1000-4000
        return 'AM4';
    }
    if (preg_match('/ATHLON/', $n)) return 'AM4';
    if (preg_match('/ATHLON\s*(?:II|X2|64|X3)/', $n)) return 'AM3';
    if (preg_match('/(FX|OPTERAN|EPYC)/', $n)) return null;

    // --- Intel Core -------------------------------------------------------
    if (preg_match('/CORE\s+I?(\d+)-(\d{4,5})/', $n, $m)) {
        $gen = (int) $m[2];
        if ($gen >= 20000) return 'LGA1851';   // Core Ultra 200
        if ($gen >= 17000) return 'LGA1700';   // 12th/13th/14th
        if ($gen >= 11000) return 'LGA1200';   // 11th
        if ($gen >= 6000)  return 'LGA1151';   // 6th/7th/8th/9th
        if ($gen >= 3000)  return 'LGA1150';   // 3rd/4th/5th
        if ($gen >= 1000)  return 'LGA1151';   // 1st/2nd
        return null;
    }
    return null;
}

/** AMD chipset family from a board name. */
function chipsetFromName(string $name): ?string {
    $n = strtoupper($name);
    if (preg_match('/\b([ABX])\s?(\d{3,4}[A-Z]?)\b/', $n, $m)) {
        return $m[1] . strtoupper($m[2]);
    }
    if (preg_match('/\b(Z\d{3}|H\d{3})\b/', $n, $m)) return strtoupper($m[1]); // Intel chipset
    return null;
}

/**
 * DDR generation for a RAM component.
 *
 * CORRECTED after measurement. My first pass read only the NAME and only
 * specs.type, and concluded the catalogue was 100% DDR5. That was WRONG and it
 * was exactly the mistake rule 1 warns about: DDR4 is present and ACTIVE (377 of
 * 377 rows mention DDR somewhere) but the generation is recorded in
 * specs.speed - e.g. {"speed":"DDR4-3200"} - while specs.type is populated on
 * only 2 rows. The field mixes three formats too: "DDR4-3200", "DDR5-6000" and
 * "6000 MT/s". So the reader must consider the name AND every specs field, not
 * the first plausible-looking one.
 */
function ramType(array $row): ?string {
    $blob = strtoupper((string) ($row['name'] ?? '') . ' ' . (string) ($row['specs'] ?? ''));
    // Order matters: check 5 before 4 so DDR5-6000 is never read as DDR4.
    if (preg_match('/DDR\s*-?\s*5/', $blob)) return 'DDR5';
    if (preg_match('/DDR\s*-?\s*4/', $blob)) return 'DDR4';
    if (preg_match('/DDR\s*-?\s*3/', $blob)) return 'DDR3';
    return null;
}

echo "########## CPU: derived socket vs stored socket ##########" . PHP_EOL;
$sql = "SELECT comp.id, comp.name, comp.socket FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'cpu' ORDER BY comp.name";
$nulls = 0; $wrong = 0; $ok = 0; $unknown = 0;
$wrongList = [];
foreach ($db->query($sql) as $r) {
    $derived = cpuSocketFromName((string) $r['name']);
    $stored  = $r['socket'] === null ? null : strtoupper((string) $r['socket']);
    if ($derived === null) { $unknown++; continue; }
    if ($stored === null) { $nulls++; continue; }
    if ($derived === $stored) { $ok++; continue; }
    $wrong++;
    if (count($wrongList) < 25) $wrongList[] = sprintf('  %-44s stored=%-9s derived=%s', $r['name'], $stored, $derived);
}
echo "matching        : $ok" . PHP_EOL;
echo "stored NULL     : $nulls" . PHP_EOL;
echo "MISMATCHED      : $wrong" . PHP_EOL;
echo "could not derive: $unknown" . PHP_EOL;
echo PHP_EOL . "Sample mismatches:" . PHP_EOL;
foreach ($wrongList as $l) echo $l . PHP_EOL;

echo PHP_EOL . "########## MOTHERBOARD: chipset stored vs derived ##########" . PHP_EOL;
$sql = "SELECT comp.name, comp.chipset, comp.socket FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'motherboard' ORDER BY comp.name";
$mNull = 0; $mOk = 0; $mUnknown = 0; $mWrong = 0; $chipsets = [];
foreach ($db->query($sql) as $r) {
    $derived = chipsetFromName((string) $r['name']);
    $stored  = $r['chipset'] === null ? null : strtoupper((string) $r['chipset']);
    if ($derived === null) { $mUnknown++; continue; }
    if ($stored === null) { $mNull++; if (count($chipsets) < 40) $chipsets[$derived] = ($chipsets[$derived] ?? 0) + 1; continue; }
    if ($derived === $stored) $mOk++; else $mWrong++;
}
echo "chipset stored NULL : $mNull" . PHP_EOL;
echo "matching            : $mOk" . PHP_EOL;
echo "mismatched          : $mWrong" . PHP_EOL;
echo "could not derive    : $mUnknown" . PHP_EOL;
echo PHP_EOL . "Derived chipset distribution (from names):" . PHP_EOL;
ksort($chipsets);
foreach ($chipsets as $c => $n) echo '  ' . str_pad($c, 14) . $n . PHP_EOL;

echo PHP_EOL . "########## RAM: type stored vs derived ##########" . PHP_EOL;
$sql = "SELECT comp.name, comp.specs FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'ram'";
$rDer = []; $rHas = 0; $rMiss = 0;
foreach ($db->query($sql) as $r) {
    $sp = json_decode((string) $r['specs'], true);
    $stored = is_array($sp) && !empty($sp['type']) ? strtoupper((string) $sp['type']) : null;
    if ($stored) $rHas++;
    $d = ramType($r);
    if ($d) { $rDer[$d] = ($rDer[$d] ?? 0) + 1; if (!$stored) $rMiss++; }
}
echo "rows with specs.type : $rHas" . PHP_EOL;
echo "rows where the NAME says DDR but specs.type is empty : $rMiss" . PHP_EOL;
foreach ($rDer as $t => $n) echo '  derived ' . str_pad($t, 8) . $n . PHP_EOL;

echo PHP_EOL . "########## PSU under 650W that would be gated out ##########" . PHP_EOL;
$sql = "SELECT comp.name, comp.wattage FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'psu' AND (comp.wattage IS NULL OR comp.wattage < 650)
        ORDER BY comp.wattage";
$low = [];
foreach ($db->query($sql) as $r) $low[] = '  ' . str_pad((string) $r['wattage'], 8) . $r['name'];
echo count($low) . ' row(s) would be hidden:' . PHP_EOL;
foreach (array_slice($low, 0, 30) as $l) echo $l . PHP_EOL;