<?php
// Genie 2026-09-28: acquire the binding constraint for the new band promise.
//
// Boss directive: the minimum 1080p build must be able to play the LATEST TOP 150
// GAMES at RECOMMENDED hardware. So the 1080p floor is no longer a hand-typed
// number or a hand-picked gpu_tier - it is whatever a machine strong enough for
// all 150 costs. This script gathers the data that decides it.
//
// Method: Steam's own most-played chart (the ISteamChartsService endpoint the
// store site itself calls, so it is first-party and not a scraper), then each
// title's publisher-stated RECOMMENDED requirements from the Steam appdetails
// API. Publisher recommended specs are the number customers judge us against,
// so they are the honest basis - inventing our own fps figures would be worse.
//
// Resumable: writes incrementally, skips appids already captured, so a timeout
// or a rate-limit does not lose the work.

$outFile = __DIR__.'/../database/scraped/steam-top150-requirements.json';
// STAMPROLL_LIMIT lets a cheap smoke run parse just N titles without hammering
// the appdetails API (it rate-limits and starts timing out under rapid calls).
$want = (int) (getenv('STEAMTOP_LIMIT') ?: 150);
if ($want < 1) {
    fwrite(STDERR, "FATAL: STEAMTOP_LIMIT must be >= 1\n");
    exit(1);
}
$target = 'GB';
$lang = 'english';
$out = file_exists($outFile) ? (json_decode(file_get_contents($outFile), true) ?: []) : [];
$out['_meta'] = [
    'source_chart' => 'ISteamChartsService/GetMostPlayedGames (first-party)',
    'source_requirements' => 'store.steampowered.com/api/appdetails (publisher recommended)',
    'region' => $target,
    'captured_at' => gmdate('c'),
    'want' => $want,
    'chart_rank_cap' => 100,
    'note' => 'The most-played chart only ever returns 100 ranks. Ranks 101-150 '
        .'require a separate sourced extension, and any extension must record its '
        .'own metric rather than being presented as part of this chart.',
];

/**
 * GET a URL, retrying on empty/failed responses.
 *
 * The appdetails API rate-limits and starts returning zero-byte bodies under
 * rapid calls. Without a retry that is indistinguishable from "this game has
 * no requirements", which would silently write a wrong dataset - so retry,
 * then report the failure rather than storing a null.
 */
function get(string $url, int $attempts = 3): ?string
{
    for ($i = 1; $i <= $attempts; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (is_string($out) && $out !== '' && $code >= 200 && $code < 300) {
            return $out;
        }

        fwrite(STDERR, sprintf("  ! fetch attempt %d/%d failed (http %d) - backing off\n", $i, $attempts, $code));
        usleep($i * 3000000);
    }

    return null;
}

/** Steam requirement blocks are HTML fragments like "Processor:<br>Intel i5-12400". */
function clean(?string $html): string
{
    $s = (string) $html;
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    // Replace tags with a SPACE, not bare strip_tags: adjacent <li> items would
    // otherwise weld into "...Windows 10Processor:..." and destroy the word
    // boundary every field lookup depends on.
    $s = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $s);
    $s = preg_replace('#<[^>]+>#', ' ', $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\u{00A0}", ' ', $s);
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);

    return trim($s);
}

/**
 * Pull one labelled field out of a requirements blob.
 *
 * Publishers are inconsistent: most use "Graphics:", older pages use
 * "Video Card:", and some use "CPU:" rather than "Processor:". Accept the
 * documented variants rather than assuming one spelling.
 *
 * @param string[] $labels
 */
function field(string $blob, array $labels): ?string
{
    $stops = 'Processor|CPU|Video Card|Graphics|Memory|RAM|OS|Storage|Network|Additional Notes|DirectX|Sound Card';

    foreach ($labels as $label) {
        if (preg_match('/'.preg_quote($label, '/').'\s*:\s*(.+?)(?=(?:'.$stops.')\s*:|$)/i', $blob, $m)) {
            $v = trim(preg_replace('/\s+/', ' ', $m[1]));

            return $v === '' ? null : $v;
        }
    }

    return null;
}

const GPU_LABELS = ['Graphics', 'Video Card', 'GPU'];
const CPU_LABELS = ['Processor', 'CPU'];

// ---- 1. the chart -------------------------------------------------------
$chart = json_decode((string) get('https://api.steampowered.com/ISteamChartsService/GetMostPlayedGames/v1/?tag=0&count=200'), true);
$ranks = $chart['response']['ranks'] ?? [];
if (! $ranks) {
    fwrite(STDERR, "FATAL: chart unavailable\n");
    exit(1);
}
$rollup = gmdate('Y-m-d', (int) $chart['response']['rollup_date']);
$list = array_slice($ranks, 0, $want);

// This endpoint only ever serves 100 ranks, so "top 150" cannot come from it
// alone. Say so out loud rather than letting the log imply 150 (Rule 5).
fwrite(STDERR, sprintf(
    "chart ok: %d ranks available (this endpoint caps at 100), rollup %s, capturing %d\n",
    count($ranks),
    $rollup,
    count($list)
));

// ---- 2. requirements, one title at a time -------------------------------
$done = 0;
foreach ($list as $r) {
    $appid = (string) $r['appid'];

    // STEAMTOP_FORCE re-fetches even when a title is already recorded, which is
    // how a parser fix gets applied to entries captured by the broken version.
    $force = (bool) getenv('STEAMTOP_FORCE');

    // Skip on an explicit fetched marker, NOT on 'gpu': a title with no stated
    // requirements legitimately stores null, and isset(null) is false, which
    // would re-fetch such titles forever.
    if (! $force && isset($out['games'][$appid]['fetched_at'])) {
        $done++;
        continue;
    }

    $raw = get("https://store.steampowered.com/api/appdetails?appids={$appid}&cc={$target}&l={$lang}");
    $j    = $raw ? json_decode($raw, true) : null;
    $data = $j[$appid]['data'] ?? null;

    // success:false is Steam's legitimate "this app has no store page" answer
    // (verified: appid 553850 returns 28 bytes of {"success":false} and its store
    // page is a "Site Error"). That is a data gap, not a transport failure, and
    // must not be reported as a broken capture or as a title with requirements.
    $success = $j[$appid]['success'] ?? null;
    if ($success === false && ! is_array($data)) {
        $out['games'][$appid] = [
            'appid'           => (int) $appid,
            'rank'             => (int) $r['rank'],
            'peak_in_game'     => (int) ($r['peak_in_game'] ?? 0),
            'metric'           => 'steam_most_played',
            'name'             => null,
            'no_store_data'    => true,
            'has_requirements' => false,
            'fetched_at'       => gmdate('c'),
        ];
        file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $done++;
        fwrite(STDERR, sprintf("  ~ appid %s: Steam reports no store page - no publisher specs exist\n", $appid));
        usleep(1500000);
        continue;
    }

    if (! is_array($data)) {
        // A failed fetch is NOT the same as a game without requirements. Record
        // the failure and leave the title unparsed so a re-run retries it.
        $out['games'][$appid] = [
            'appid'           => (int) $appid,
            'rank'           => (int) $r['rank'],
            'peak_in_game'   => (int) ($r['peak_in_game'] ?? 0),
            'metric'         => 'steam_most_played',
            'name'           => null,
            'fetch_failed'   => true,
            'fetch_failed_at' => gmdate('c'),
            'has_requirements' => null,
        ];
        file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $done++;
        fwrite(STDERR, sprintf("  ! appid %s unparseable after retries - will retry next run\n", $appid));
        usleep(4000000);
        continue;
    }

    // Verified against a live payload: pc_requirements['recommended'] and
    // ['minimum'] are PLAIN HTML STRINGS. There is no ['raw'] sub-key, and
    // 'recommended' is absent entirely for titles that only state minimums.
    $req = $data['pc_requirements'] ?? [];
    $rec = clean(is_string($req['recommended'] ?? null) ? $req['recommended'] : null);
    $min = clean(is_string($req['minimum'] ?? null) ? $req['minimum'] : null);

    $gpuRec = $rec !== '' ? field($rec, GPU_LABELS) : null;
    $cpuRec = $rec !== '' ? field($rec, CPU_LABELS) : null;
    $gpuMin = $min !== '' ? field($min, GPU_LABELS) : null;
    $cpuMin = $min !== '' ? field($min, CPU_LABELS) : null;

    $out['games'][$appid] = [
        'rank' => (int) $r['rank'],
        'peak_in_game' => (int) ($r['peak_in_game'] ?? 0),
        'metric' => 'steam_most_played',
        'name' => $data['name'] ?? null,
        'fetched_at' => gmdate('c'),
        'has_requirements' => $rec !== '' || $min !== '',
        'has_recommended' => $rec !== '',
        'has_minimum' => $min !== '',
        // Primary fields prefer RECOMMENDED (the customer-facing promise) and
        // fall back to MINIMUM so a title that only states minimums is not lost.
        'gpu' => $gpuRec ?? $gpuMin,
        'cpu' => $cpuRec ?? $cpuMin,
        'gpu_recommended' => $gpuRec,
        'cpu_recommended' => $cpuRec,
        'gpu_minimum' => $gpuMin,
        'cpu_minimum' => $cpuMin,
        'raw_recommended' => $rec !== '' ? $rec : null,
        'raw_minimum' => $min !== '' ? $min : null,
    ];

    file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $done++;

    if ($done % 10 === 0) {
        fwrite(STDERR, sprintf("  %d/%d captured\n", $done, count($list)));
    }
    usleep(1200000);
}

file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$games    = $out['games'];
$failedAt = array_filter($games, fn ($g) => ! empty($g['fetch_failed']));
$noStore  = array_filter($games, fn ($g) => ! empty($g['no_store_data']));
$parsed   = array_filter($games, fn ($g) => empty($g['fetch_failed']));
$withReq  = count(array_filter($parsed, fn ($g) => ! empty($g['has_requirements'])));
$withRec  = count(array_filter($parsed, fn ($g) => ! empty($g['has_recommended'])));
$withMin  = count(array_filter($parsed, fn ($g) => ! empty($g['has_minimum'])));
$noReq    = array_values(array_filter($parsed, fn ($g) => empty($g['has_requirements'])));

fwrite(STDERR, sprintf(
    "DONE: %d titles in %s\n"
    ."  parsed %d/%d | with any requirements %d (recommended %d, minimum %d)\n"
    ."  fetch failures %d | no store page %d | state no requirements %d\n",
    count($games),
    basename($outFile),
    count($parsed),
    count($games),
    $withReq,
    $withRec,
    $withMin,
    count($failedAt),
    count($noStore),
    count($noReq)
));

if ($failedAt) {
    // A failed fetch is never reported as a completed capture.
    fwrite(STDERR, sprintf("  WARNING: %d titles could not be parsed and need a re-run: %s\n",
        count($failedAt),
        implode(', ', array_map(fn ($g) => (string) ($g['name'] ?? '?'), array_slice($failedAt, 0, 12)))
    ));
}

if ($noStore) {
    // A high-ranked title with no store page has no publisher specs at all. It
    // must be named as a gap in any claim of top-150 coverage.
    $noStoreRanks = array_map(fn ($g) => '#'.$g['rank'].' appid '.($g['appid'] ?? '?'), $noStore);
    fwrite(STDERR, sprintf("  GAP: %d titles have no Steam store page, so no publisher requirements exist: %s\n",
        count($noStore),
        implode(', ', array_slice($noStoreRanks, 0, 12))
    ));
}

if ($noReq) {
    // Announce the real gap rather than letting it pass as complete coverage.
    fwrite(STDERR, sprintf("  NOTE: %d titles genuinely state no requirements: %s\n",
        count($noReq),
        implode(', ', array_slice(array_map(fn ($g) => (string) $g['name'], $noReq), 0, 12))
    ));
}
