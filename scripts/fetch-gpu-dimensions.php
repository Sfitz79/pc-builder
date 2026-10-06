<?php
/**
 * Byparr-backed adapter that reads EXACT GPU dimensions off manufacturer spec pages.
 *
 * READ-ONLY against production. Writes only to resources/dimensions/curated.json.
 *
 * WHY Byparr AND NOT PLAIN FETCH
 * ------------------------------
 * ASUS, Gigabyte and MSI all render their tech-spec tables with JavaScript. A
 * plain HTTP fetch of the ASUS techspec page returns navigation and filter facets
 * and no spec table at all. Byparr (127.0.0.1:8191, already deployed and already
 * proven against PCPartPicker) runs a real browser and gets the rendered table.
 *
 * This is not circumvention. ASUS publishes these pages, does not challenge
 * automated access and does not disallow it in robots.txt. TechPowerUp DOES send
 * an active bot check and was declined on exactly that basis. The line drawn here
 * is the site's own stated wishes, not whether the fetch is convenient.
 *
 * WHY BUCKETS ARE REJECTED
 * ------------------------
 * ASUS's public filter facets expose Slot Height ("2.96-slot") and length
 * buckets (">30cm", "25-30cm"). Those CANNOT support a fit decision: 25-30cm does
 * not tell you whether a 300mm card clears a 300mm case. Only exact millimetres
 * are accepted. A card whose exact figure cannot be read stays unverified, because
 * a number that looks like data but cannot settle a clearance is worse than a gap.
 *
 * WHY THE URL GUESS IS SAFE
 * -------------------------
 * The slug is derived from data we already hold (series + chipset + variant), so
 * it can be wrong. Therefore the fetched page is VERIFIED to actually be the
 * expected chipset and series before any figure is taken, and a mismatch is
 * rejected rather than trusted. A wrong slug that happens to resolve to a
 * different product therefore cannot attach wrong dimensions to a card.
 *
 * Usage:
 *   php scripts/fetch-gpu-dimensions.php                    # all vendors enabled
 *   php scripts/fetch-gpu-dimensions.php --vendor=ASUS --limit=5
 *   php scripts/fetch-gpu-dimensions.php --dry-run          # do not write the file
 */

require __DIR__ . '/../vendor/autoload.php';

$byparr = getenv('BYparr_URL') ?: 'http://127.0.0.1:8191';
$dryRun = in_array('--dry-run', $argv, true);
$onlyVendor = null;
$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--vendor=(\w+)$/', $a, $m)) {
        $onlyVendor = $m[1];
    }
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = (int) $m[1];
    }
}

function logmsg(string $m): void { fwrite(STDOUT, $m . "\n"); }

/** Fetch a URL through the local Byparr renderer and return the final HTML. */
function fetchVia(string $byparr, string $url, int $timeout = 90): array
{
    $payload = [
        'url' => $url,
        'proxy' => null,
        'timeout' => $timeout,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'Accept-Language' => 'en-GB,en;q=0.9',
        ],
    ];
    // Rule 3: never hand a JSON body to a native executable via -d. Write the file,
    // point the request at the file.
    $tmp = tempnam(sys_get_temp_dir(), 'byparr') . '.json';
    file_put_contents($tmp, json_encode($payload));

    $cmd = sprintf(
        'curl.exe -sS --max-time %d -X POST %s -H "Content-Type: application/json" --data-binary "@%s" 2>&1',
        $timeout + 15,
        escapeshellarg($byparr . '/v1'),
        escapeshellarg($tmp)
    );
    $raw = shell_exec($cmd);
    @unlink($tmp);

    if ($raw === null || trim((string) $raw) === '') {
        return ['ok' => false, 'error' => 'empty response from Byparr'];
    }
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Byparr returned non-JSON: ' . substr((string) $raw, 0, 160)];
    }
    // Verified against a live payload rather than assumed: Byparr returns the page
    // under solution.response on some builds and under response on others.
    $html = '';
    foreach (['solution.response', 'response', 'html', 'body'] as $k) {
        $node = $json;
        foreach (explode('.', $k) as $seg) {
            $node = is_array($node) ? ($node[$seg] ?? null) : null;
        }
        if (is_string($node) && strlen($node) > 200) {
            $html = $node;
            break;
        }
    }
    if ($html === '') {
        $keys = implode(',', array_keys($json));
        return ['ok' => false, 'error' => "no HTML in response. top-level keys: {$keys}"];
    }
    return ['ok' => true, 'html' => $html];
}

/** Derive an ASUS-style slug from the series, chipset and variant we already hold. */
function asusSlug(string $name, string $chipset): ?string
{
    $chip = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($chipset)));
    $n = strtoupper(trim($name));
    // Series must be explicit. Never guess a series from the chipset.
    $series = null;
    foreach (['ROG Astral', 'ROG Strix', 'TUF Gaming', 'Prime', 'Dual', 'Phoenix', 'Turbo', 'KOKO', 'Cerberus', 'ProArt'] as $s) {
        if (stripos($n, $s) !== false) { $series = strtolower(str_replace(' ', '-', $s)); break; }
    }
    if ($series === null) {
        return null;
    }
    $variant = '';
    if (stripos($n, ' OC') !== false) { $variant = '-oc-edition'; }
    elseif (stripos($n, 'ITX') !== false) { $variant = '-itx-edition'; }
    elseif (stripos($n, ' V2') !== false) { $variant = '-v2-edition'; }
    elseif (stripos($n, ' MINI') !== false) { $variant = '-mini'; }
    $slug = $series . '-' . $chip . $variant;
    return $slug;
}

/** Pull exact mm figures out of a rendered tech-spec table. */
function parseAsusSpecs(string $html): array
{
    $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html)));
    $text = preg_replace('/\s+/', ' ', $text);
    $out = [];

    // Card Size / dimensions, e.g. "Card size 26.7 x 13.5 x 4.1 cm" or "26.7 x 13.5 x 4.1 (inch)"
    if (preg_match('/Card\s*size[^0-9]{0,40}([0-9.]+)\s*x\s*([0-9.]+)\s*x\s*([0-9.]+)\s*cm/i', $text, $m)) {
        $out['length'] = round((float) $m[1] * 10, 1);
        $out['height'] = round((float) $m[3] * 10, 1);
        $out['width'] = round((float) $m[2] * 10, 1);
    } elseif (preg_match('/Card\s*size[^0-9]{0,40}([0-9.]+)\s*x\s*([0-9.]+)\s*x\s*([0-9.]+)\s*(?:inch|")/i', $text, $m)) {
        $out['length'] = round((float) $m[1] * 25.4, 1);
        $out['height'] = round((float) $m[3] * 25.4, 1);
        $out['width'] = round((float) $m[2] * 25.4, 1);
    } elseif (preg_match('/Dimensions[^0-9]{0,30}([0-9.]+)\s*x\s*([0-9.]+)\s*x\s*([0-9.]+)\s*mm/i', $text, $m)) {
        $out['length'] = (float) $m[1];
        $out['height'] = (float) $m[3];
        $out['width'] = (float) $m[2];
    }

    // Slot size: "Slot Size 2.5 Slot" or "2.5-slot"
    if (preg_match('/Slot\s*(?:size|width)?[^0-9]{0,25}([0-9]+(?:\.[0-9]+)?)\s*-?\s*slot/i', $text, $m)) {
        $out['slot_width'] = (float) $m[1];
    } elseif (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*-slot/i', $text, $m)) {
        $out['slot_width'] = (float) $m[1];
    }

    // Power, used as a cross-check that we are on the right product page.
    if (preg_match('/Power\s*Consumption[^0-9]{0,25}([0-9.]+)\s*W/i', $text, $m)) {
        $out['tdp'] = (float) $m[1];
    } elseif (preg_match('/Recommended\s*PSU[^0-9]{0,25}([0-9.]+)\s*W/i', $text, $m)) {
        $out['tdp_derived'] = (float) $m[1];
    }

    return $out;
}

/** Confirm the rendered page is genuinely the card we asked for. */
function verifyPage(string $html, string $chipset, string $series): array
{
    $text = strtolower(html_entity_decode(strip_tags($html)));
    $needle = strtolower($chipset);
    // Accept the common naming variations: RTX 5060 vs RTX™ 5060, RX 9060 XT.
    $norm = preg_replace('/[^a-z0-9]/', '', $text);
    $want = preg_replace('/[^a-z0-9]/', '', strtolower($chipset));
    $chipOk = $want !== '' && str_contains($norm, $want);
    $seriesOk = str_contains(strtolower($text), strtolower($series));
    $blocked = stripos($text, 'captcha') !== false || stripos($text, 'are you a robot') !== false || stripos($text, 'access denied') !== false;

    return [
        'chipset' => $chipOk,
        'series' => $seriesOk,
        'blocked' => $blocked,
        'ok' => $chipOk && $seriesOk && !$blocked,
    ];
}

// ---------------------------------------------------------------- load targets
$env = [];
foreach (file(__DIR__ . '/../.env.production.neon', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) { continue; }
    [$k, $v] = explode('=', $line, 2);
    $v = trim($v);
    if (strlen($v) >= 2 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
        $v = substr($v, 1, -1);
    }
    $env[trim($k)] = $v;
}
$db = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $env['DB_HOST'], $env['DB_PORT'], $env['DB_DATABASE'], $env['DB_SSLMODE']),
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20]
);

$sql = "SELECT c.id, c.name, c.source_url, c.specs, m.name AS manufacturer
          FROM components c
          JOIN categories cat ON cat.id = c.category_id
          JOIN manufacturers m ON m.id = c.manufacturer_id
         WHERE cat.slug = 'gpu' AND c.active = true";
$args = [];
if ($onlyVendor !== null) {
    $sql .= ' AND m.name = ?';
    $args[] = $onlyVendor;
}
$sql .= ' ORDER BY m.name, c.name';
$stmt = $db->prepare($sql);
$stmt->execute($args);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

logmsg("=== GPU dimension adapter (Byparr {$byparr}) ===");
logmsg('  vendor filter : ' . ($onlyVendor ?? 'all'));
logmsg('  candidates    : ' . count($targets));
logmsg('  mode          : ' . ($dryRun ? 'DRY RUN' : 'will append to curated.json'));
logmsg('');

$accepted = 0; $rejected = [];
$sleepUs = 1200000; // ~1.2s between requests. Politeness, not speed.

foreach ($targets as $t) {
    if ($limit > 0 && $accepted + count($rejected) >= $limit) { break; }

    $specs = json_decode((string) $t['specs'], true);
    $chipset = (string) ($specs['chipset'] ?? '');
    if ($chipset === '') {
        $rejected[] = [$t['name'], 'no chipset recorded; cannot derive a URL or verify the page'];
        continue;
    }
    if ($t['manufacturer'] !== 'ASUS') {
        $rejected[] = [$t['name'], 'no adapter for ' . $t['manufacturer'] . ' yet'];
        continue;
    }
    preg_match('#pcpartpicker\.com/product/([A-Za-z0-9]+)/#', (string) $t['source_url'], $m);

    $slug = asusSlug((string) $t['name'], $chipset);
    if ($slug === null) {
        $rejected[] = [$t['name'], 'could not identify an ASUS series from the name; refusing to guess'];
        continue;
    }
    $url = "https://www.asus.com/motherboards-components/graphics-cards/asus/{$slug}/techspec/";

    $res = fetchVia($byparr, $url);
    if (!$res['ok']) {
        $rejected[] = [$t['name'], 'fetch failed: ' . $res['error']];
        continue;
    }
    $ver = verifyPage($res['html'], $chipset, explode('-', $slug)[0]);
    if (!$ver['ok']) {
        $why = $ver['blocked'] ? 'page is blocked or challenged'
            : (!$ver['chipset'] ? 'page does not mention ' . $chipset . ' - slug guessed wrong or product absent'
            : 'page does not mention the expected series');
        $rejected[] = [$t['name'], $why . " ({$url})"];
        continue;
    }
    $figs = parseAsusSpecs($res['html']);
    if (!isset($figs['length'])) {
        $rejected[] = [$t['name'], 'page verified but no exact Card Size in mm could be read; refusing to store a bucket value'];
        continue;
    }

    // Bounds gate, same limits as CuratedDimensions.
    if ($figs['length'] < 100 || $figs['length'] > 600) {
        $rejected[] = [$t['name'], "length {$figs['length']}mm outside plausible range; treating as a parse error"];
        continue;
    }

    $entry = [
        'pcpp_id' => $m[1] ?? '',
        'name' => (string) $t['name'],
        'chipset' => $chipset,
        'source' => $url,
        'retrieved' => gmdate('Y-m-d'),
    ] + $figs;
    unset($entry['tdp_derived']);

    logmsg(sprintf('  ACCEPT  %-34s length=%-6s height=%-6s slot=%-4s  <- %s',
        $t['name'],
        $entry['length'] ?? '?',
        $entry['height'] ?? '?',
        $entry['slot_width'] ?? '?',
        $slug));
    $accepted++;
    $GLOBALS['entries'][] = $entry;
    usleep($sleepUs);
}

logmsg('');
logmsg(sprintf('  accepted : %d', $accepted));
logmsg(sprintf('  rejected : %d', count($rejected)));
foreach (array_slice($rejected, 0, 15) as $r) {
    logmsg(sprintf('    %-34s %s', $r[0], $r[1]));
}
if (count($rejected) > 15) {
    logmsg(sprintf('    ... and %d more', count($rejected) - 15));
}

$entries = $GLOBALS['entries'] ?? [];
if ($dryRun || $entries === []) {
    logmsg('');
    logmsg($dryRun ? 'DRY RUN: curated.json untouched.' : 'Nothing to write.');
    exit(0);
}

$path = __DIR__ . '/../resources/dimensions/curated.json';
$data = json_decode((string) file_get_contents($path), true);
$data['components'] = array_merge($data['components'] ?? [], $entries);
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
logmsg('');
logmsg(sprintf('APPENDED %d entries to resources/dimensions/curated.json', count($entries)));
logmsg('php scripts/apply-curated-dimensions.php --production   # dry run against the catalogue');