<?php
/**
 * READ-ONLY. Verify the Boss's DDR-from-speed rule against the real catalogue
 * before implementing it.
 *
 * Proposed rule: when the DDR generation is not stated, infer from speed:
 *   DDR4 = 3200, 3600
 *   DDR5 = 4800, 5600, 6000, 7000, 7200, 8000
 *
 * Two things to check before trusting it:
 *  1. The two sets are DISJOINT, so a speed maps to exactly one generation.
 *     Confirmed by inspection: no number appears in both lists.
 *  2. What speeds ACTUALLY occur that are in NEITHER list. Those must not be
 *     guessed at - a kit at an unlisted speed has to be reported as unknown, or
 *     the grouping silently puts DDR4 in the DDR5 bucket.
 */
$db = new PDO('sqlite:database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const DDR4_SPEEDS = [3200, 3600];
const DDR5_SPEEDS = [4800, 5600, 6000, 7000, 7200, 8000];

echo "overlap between the two lists: " . (array_intersect(DDR4_SPEEDS, DDR5_SPEEDS) ? 'YES - AMBIGUOUS' : 'none (disjoint)') . PHP_EOL;

/** Pull the leading number out of whatever format speed is stored in. */
function speedNumber($raw): ?int {
    if (!is_scalar($raw)) return null;
    if (preg_match('/(\d{4,5})/', (string) $raw, $m)) return (int) $m[1];
    return null;
}

$stated = 0; $inferred = 0; $unknown = [];
$speedCounts = [];

$sql = "SELECT comp.name, comp.specs FROM components comp
        JOIN categories c ON c.id = comp.category_id
        WHERE comp.active = 1 AND c.slug = 'ram'";

foreach ($db->query($sql) as $r) {
    $name = (string) $r['name'];
    $sp = json_decode((string) $r['specs'], true);
    $sp = is_array($sp) ? $sp : [];
    $speedRaw = $sp['speed'] ?? null;
    $typeRaw  = $sp['type']  ?? null;
    $num = speedNumber($speedRaw);
    if ($num) $speedCounts[$num] = ($speedCounts[$num] ?? 0) + 1;

    // Tier 1: stated explicitly, either in specs.type or anywhere in name/specs.
    $blob = strtoupper($name . ' ' . (string) $r['specs']);
    if (preg_match('/DDR\s*-?\s*([345])/', $blob)) { $stated++; continue; }
    if ($typeRaw && preg_match('/DDR\s*-?\s*([345])/i', (string) $typeRaw)) { $stated++; continue; }

    // Tier 2: infer from speed.
    if ($num !== null && in_array($num, DDR4_SPEEDS, true)) { $inferred++; continue; }
    if ($num !== null && in_array($num, DDR5_SPEEDS, true)) { $inferred++; continue; }

    $unknown[] = sprintf('  %-46s speed=%-14s type=%s', mb_substr($name, 0, 44),
        var_export($speedRaw, true), var_export($typeRaw, true));
}

echo PHP_EOL . "generation stated in the data : $stated" . PHP_EOL;
echo "inferred from speed (the rule)   : $inferred" . PHP_EOL;
echo "NOT resolvable by either         : " . count($unknown) . PHP_EOL;

echo PHP_EOL . "########## distinct speeds present ##########" . PHP_EOL;
ksort($speedCounts);
foreach ($speedCounts as $sp => $n) {
    $tag = in_array($sp, DDR4_SPEEDS, true) ? 'DDR4'
        : (in_array($sp, DDR5_SPEEDS, true) ? 'DDR5' : '*** NOT IN EITHER LIST ***');
    echo '  ' . str_pad((string) $sp, 8) . str_pad((string) $n, 6) . $tag . PHP_EOL;
}

echo PHP_EOL . "########## rows the rule cannot resolve ##########" . PHP_EOL;
if (!$unknown) {
    echo "  none - every active RAM row either states its generation or carries a listed speed." . PHP_EOL;
} else {
    foreach (array_slice($unknown, 0, 30) as $u) echo $u . PHP_EOL;
}

echo PHP_EOL . "########## cross-check: does any DDR4-labelled row carry a DDR5-only speed? ##########" . PHP_EOL;
$conflicts = [];
foreach ($db->query($sql) as $r) {
    $sp = json_decode((string) $r['specs'], true);
    $sp = is_array($sp) ? $sp : [];
    $num = speedNumber($sp['speed'] ?? null);
    if ($num === null) continue;
    $blob = strtoupper((string) $r['name'] . ' ' . (string) $r['specs']);
    if (preg_match('/DDR\s*-?\s*([345])/', $blob, $m)) {
        $labelled = 'DDR' . $m[1];
        $fromSpeed = in_array($num, DDR4_SPEEDS, true) ? 'DDR4' : (in_array($num, DDR5_SPEEDS, true) ? 'DDR5' : 'unknown');
        if ($fromSpeed !== 'unknown' && $labelled !== $fromSpeed) {
            $conflicts[] = '  ' . str_pad(mb_substr((string) $r['name'], 0, 42), 44)
                . ' labelled=' . $labelled . ' speed=' . $num . ' implies=' . $fromSpeed;
        }
    }
}
if (!$conflicts) {
    echo "  none - every stated label agrees with the speed. The rule is safe to apply as a fallback." . PHP_EOL;
} else {
    echo count($conflicts) . ' CONFLICT(S) - the rule would contradict the data:' . PHP_EOL;
    foreach (array_slice($conflicts, 0, 20) as $c) echo $c . PHP_EOL;
}