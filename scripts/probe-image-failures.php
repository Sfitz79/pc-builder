<?php
/**
 * Probe the PCPP image-import failures against the local catalogue.
 *
 * 106 failures reported as "no image url in page" is 13% of the run. That is
 * either a real data gap (PCPP genuinely has no photo) or a broken fetcher.
 * Do not guess which: print what these components actually are, so the sample
 * can be checked against the live pages.
 *
 * Usage: php scripts\probe-image-failures.php [report.json] [sqlite path]
 */

$root       = dirname(__DIR__);
$reportPath = $argv[1] ?? ($root . '/database/scraped/pcpp-image-fetch-report.json');
$dbPath     = $argv[2] ?? ($root . '/database/database.sqlite');

$report = json_decode((string) file_get_contents($reportPath), true);
$failed = $report['failed'] ?? [];
$ids    = array_map('intval', array_keys($failed));
sort($ids);

echo 'total failed: ' . count($ids) . "\n";

// Reason breakdown, so a new failure mode cannot hide inside the bulk count.
//
// Failure values are NOT always strings. Older runs recorded a bare reason
// string; the importer now records a structured object with reason/name/url
// plus the raw signals. Accept both, and say which format was found, because
// "the report is in the old format" is itself the finding that matters.
$byReason = [];
$format   = 'string';
foreach ($failed as $id => $rec) {
    if (is_array($rec)) {
        $format = 'structured';
        $reason = (string) ($rec['reason'] ?? '(no reason)');
    } else {
        $reason = (string) $rec;
    }
    $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
}
echo "failure record format: $format\n";
echo "reasons:\n";
foreach ($byReason as $reason => $n) {
    echo sprintf("  %5d  %s\n", $n, $reason);
}

// A structured report can tell us whether these were ever really data gaps.
if ($format === 'structured') {
    $small = 0;
    $withImg = 0;
    foreach ($failed as $id => $rec) {
        if (! is_array($rec)) {
            continue;
        }
        if ((int) ($rec['html_bytes'] ?? 0) < 5000) {
            $small++;
        }
        if (! empty($rec['any_img_tag']) || ! empty($rec['og_any'])) {
            $withImg++;
        }
    }
    echo sprintf(
        "  -> %d of %d failures came back as a page under 5 KB (block, not a data gap)\n",
        $small, count($failed)
    );
    echo sprintf("  -> %d of %d had img markup present yet no URL extracted\n", $withImg, count($failed));
}

// Spread the sample across the whole id range rather than the first N, so a
// mid-run degradation cannot hide behind a healthy start.
$sample = [];
$want   = 12;
$step   = max(1, (int) floor(count($ids) / $want));
for ($i = 0; $i < count($ids) && count($sample) < $want; $i += $step) {
    $sample[] = $ids[$i];
}
$last = end($ids);
if ($last !== false && !in_array($last, $sample, true)) {
    $sample[] = $last;
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$in = implode(',', array_map('intval', $sample));
$stmt = $db->query("SELECT id, name, source_url, price FROM components WHERE id IN ($in) ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nsample of " . count($rows) . " failing components (spread across the range):\n";
foreach ($rows as $r) {
    printf(
        "  %-6d %-46s GBP %-9s %s\n",
        $r['id'],
        mb_strimwidth((string) $r['name'], 0, 44, '...'),
        $r['price'] ?? '?',
        (string) $r['source_url']
    );
}

// A URL that is null or not a pcpp product page would mean the FETCHER is at
// fault, not PCPP. That is the single most useful discriminator here.
$bad = 0;
foreach ($rows as $r) {
    if (!$r['source_url'] || !preg_match('~pcpartpicker\.com/product/~', (string) $r['source_url'])) {
        $bad++;
    }
}
echo "\nsample rows with a missing/non-PCPP source_url: $bad of " . count($rows) . "\n";
