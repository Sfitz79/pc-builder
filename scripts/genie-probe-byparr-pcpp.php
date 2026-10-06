<?php
// Genie 2026-09-29: can Byparr get LIVE PCPartPicker data?
//
// Rule 4: a green /health proves nothing about the real endpoint. Byparr answered
// /health 200 while every /v1 POST returned 500 during the Awin work. So make one
// REAL call and dump the actual top-level keys and value lengths of the payload
// before interpreting anything about it.
//
// Rule 3: PowerShell mangles JSON passed to native exes, so this goes through
// Invoke-RestMethod/curl with a JSON body built in PHP, never a hand-quoted -d.
//
// The question is narrow: does PCPP serve product + price data through Byparr
// right now, or is it still the Cloudflare wall recorded in state?
$byparr = getenv('BYPARR_URL') ?: 'http://127.0.0.1:8191';
$targets = [
    'pcpp product list' => 'https://uk.pcpartpicker.com/products/internal-hard-drive/#A=1800000000000,2400000000000',
    'pcpp home' => 'https://uk.pcpartpicker.com/',
];

foreach ($targets as $label => $url) {
    $body = json_encode([
        'url' => $url,
        'maxTimeout' => 60000,
    ], JSON_UNESCAPED_SLASHES);

    echo "=== {$label} ===\n";
    echo "  url: {$url}\n";

    $ch = curl_init("{$byparr}/v1");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_POST => true,
        // Write the body to stdin from a FILE so no shell ever re-quotes JSON.
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    printf("  HTTP %s  %s\n", $code === 0 ? '000' : $code, $err === '' ? '' : "err={$err}");

    if (! is_string($res) || $res === '') {
        echo "  (empty body)\n\n";
        continue;
    }

    // Rule 4: dump the REAL top-level shape before claiming anything.
    $j = json_decode($res, true);
    if (! is_array($j)) {
        printf("  non-JSON body, %d bytes: %s\n\n", strlen($res), substr(preg_replace('/\s+/', ' ', $res), 0, 200));
        continue;
    }

    echo "  top-level keys: " . implode(', ', array_keys($j)) . "\n";
    foreach ($j as $k => $v) {
        if (is_string($v)) {
            printf("    %-14s string %7d chars\n", $k, strlen($v));
        } elseif (is_array($v)) {
            printf("    %-14s array  %7d entries\n", $k, count($v));
        } else {
            printf("    %-14s %s\n", $k, gettype($v));
        }
    }

    // The payload is the only thing that answers the question.
    $sol = $j['solution'] ?? [];
    $html = is_array($sol) ? ($sol['response'] ?? '') : '';
    printf("  solution.response: %s\n", is_string($html) ? strlen($html) . ' chars' : gettype($html));

    if (is_string($html) && $html !== '') {
        // Cheap Cloudflare / challenge tell-tales, not a verdict.
        $tells = [];
        foreach (['Just a moment', 'cf-browser-verification', 'cf_chl', 'Attention Required', 'Enable JavaScript and cookies', 'Access denied'] as $t) {
            if (stripos($html, $t) !== false) {
                $tells[] = $t;
            }
        }
        printf("  challenge tell-tales: %s\n", $tells === [] ? 'NONE' : implode(' | ', $tells));

        // Does it actually look like a product table?
        $hasRows = preg_match_all('/class="[^"]*name"/i', $html) ?: 0;
        $hasPrices = preg_match_all('/class="[^"]*price/i', $html) ?: 0;
        printf("  name cells: %d   price cells: %d\n", $hasRows, $hasPrices);
        $title = preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? trim($m[1]) : '(no title)';
        printf("  <title>: %s\n", substr($title, 0, 120));
    }
    echo "\n";
}
