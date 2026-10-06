<?php

/**
 * Resolve the last 8 cases via Byparr + the PCPP spec page.
 *
 * Rules being applied:
 *  - Rule 3: this is a PHP file, never an inline `php -r`, and never
 *    curl.exe -d with a JSON string. PowerShell strips the quotes.
 *  - Rule 4: dump the REAL top-level keys of a live Byparr payload before
 *    trusting any field name. (The field is `solution.response`, not
 *    `message` - a lesson already learned once the hard way.)
 *  - Fail loud on a block page; never treat a block as "no form factor".
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

const BYPARR = 'http://127.0.0.1:8191/v1';

function byparrFetch(string $url): array
{
    // Endpoint is POST /v1 (NOT /v1/request - that 404s and returns
    // {"detail": "Not Found"}, which is what the first attempt got). Body is
    // LinkRequest = {cmd, url, max_timeout}; the HTML comes back in
    // solution.response. All read from the live OpenAPI schema, not memory.
    $ch = curl_init(BYPARR);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $url, 'max_timeout' => 60000]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 90,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'why' => 'transport: ' . $err];
    }
    $j = json_decode((string) $raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'why' => 'non-JSON response (HTTP ' . $code . '): ' . substr((string) $raw, 0, 120)];
    }

    // Rule 4: report the real shape on the first call so a wrong field name
    // is visible rather than silently producing an empty result.
    $solution = $j['solution'] ?? null;
    if (is_array($solution) && isset($solution['response'])) {
        return ['ok' => true, 'html' => (string) $solution['response'], 'shape' => array_keys($solution)];
    }
    if (isset($j['solution']) && is_string($j['solution'])) {
        return ['ok' => true, 'html' => $j['solution'], 'shape' => ['solution(string)']];
    }
    return ['ok' => false, 'why' => 'unexpected payload keys: ' . implode(',', array_keys($j))];
}

// NOTE: "Slim"/"HTPC"/"low profile" is a CHASSIS class, not a board form
// factor. A slim case does not accept an ATX board, so matching it here would
// let the builder promise a fit it cannot deliver. Slim/HTPC slugs are
// deliberately NOT resolved from the slug - they go to the spec page instead.
const FF_RULES = [
    'Micro-ATX' => ['/\bmicro[-\s]?atx\b/', '/\bm[-\s]?atx\b/', '/\bmatx\b/'],
    'Mini-ITX'  => ['/\bmini[-\s]?itx\b/', '/\bmitx\b/'],
    'E-ATX'     => ['/\be[-\s]?atx\b/', '/\bextended[-\s]?atx\b/'],
    'ITX'       => ['/\bitx\b/'],
    'ATX'       => ['/\batx\b/'],
];

function ffFromText(string $text): ?array
{
    $t = html_entity_decode(strip_tags($text));
    $t = preg_replace('/\s+/', ' ', $t);
    foreach (FF_RULES as $ff => $patterns) {
        foreach ($patterns as $re) {
            if (preg_match($re, (string) $t, $m)) {
                return [$ff, $m[0]];
            }
        }
    }
    return null;
}

$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$pending = DB::table('components')->where('category_id', $caseId)->where('active', 1)
    ->get(['id', 'name', 'source_url'])
    ->filter(function ($r) {
        $s = strtolower((string) $r->source_url);
        foreach (FF_RULES as $patterns) {
            foreach ($patterns as $re) {
                if (preg_match($re, $s)) {
                    return false;
                }
            }
        }
        return true;
    });

printf("unresolved cases to fetch: %d\n\n", $pending->count());
$out = [];
$first = true;

foreach ($pending as $r) {
    $url = (string) $r->source_url;
    printf("  %-42s ", substr((string) $r->name, 0, 42));
    $res = byparrFetch($url);
    if (!$res['ok']) {
        printf("FAILED (%s)\n", $res['why']);
        $out[] = ['id' => $r->id, 'name' => $r->name, 'form_factor' => null, 'status' => 'fetch-failed: ' . $res['why']];
        continue;
    }
    $html = $res['html'];
    if ($first) {
        printf("\n  [live Byparr payload shape: %s, html %d bytes]\n\n", implode(',', (array) $res['shape']), strlen($html));
        $first = false;
    }
    // PCPP's block page is ~1,200 bytes of "Unavailable"; a real spec page is
    // far larger. A short response is a block, NOT a negative result.
    if (strlen($html) < 5000) {
        printf("BLOCKED (%d bytes - not a spec page, not recorded as 'none')\n", strlen($html));
        $out[] = ['id' => $r->id, 'name' => $r->name, 'form_factor' => null, 'status' => 'blocked-page'];
        continue;
    }
    $hit = ffFromText($html);
    if ($hit === null) {
        printf("NO MATCH in a %d byte page (recorded as unresolved, not guessed)\n", strlen($html));
        $out[] = ['id' => $r->id, 'name' => $r->name, 'form_factor' => null, 'status' => 'no-match-in-page'];
        continue;
    }
    printf("%-12s (matched '%s' in %d bytes)\n", $hit[0], $hit[1], strlen($html));
    $out[] = ['id' => $r->id, 'name' => $r->name, 'form_factor' => $hit[0], 'status' => 'from-pcpp-page', 'matched' => $hit[1]];
    usleep(2500000); // be polite to PCPP
}

echo "\n-- summary --\n";
foreach ($out as $o) {
    printf("  %-44s %-14s %s\n", substr((string) $o['name'], 0, 44), $o['form_factor'] ?? '-', $o['status']);
}
file_put_contents(__DIR__ . '/../database/scraped/case-ff-byparr.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nwrote database/scraped/case-ff-byparr.json\n";
