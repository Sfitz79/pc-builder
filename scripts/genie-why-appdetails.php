<?php

/**
 * genie-why-appdetails.php - why does the Steam appdetails payload carry no
 * requirements?
 *
 * The top-150 capture reported 0/100 titles with requirements even though the
 * titles themselves parsed fine (names came through), so the failure is inside
 * the payload, not the request or the JSON decode. This dumps the REAL shape of
 * one appdetails response so the parser is written against evidence rather
 * than against a remembered key path.
 *
 * Usage:  php scripts\genie-why-appdetails.php [appid] [cc] [lang]
 */

$appid = $argv[1] ?? '730';
$cc    = $argv[2] ?? 'gb';
$lang  = $argv[3] ?? 'english';

// Passing '-' omits the parameter entirely, because cc/l are a known source of
// trimmed payloads and we need to see which parameter is responsible.
$q = [];
if ($cc !== '-') {
    $q[] = 'cc=' . rawurlencode($cc);
}
if ($lang !== '-') {
    $q[] = 'l=' . rawurlencode($lang);
}
$q[] = 'appids=' . rawurlencode($appid);

$url  = 'https://store.steampowered.com/api/appdetails?' . implode('&', $q);
$ch   = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
]);
$raw  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

printf("URL        : %s\n", $url);
printf("HTTP       : %s  curlErr: %s\n", $http ?: 'none', $err ?: 'none');
printf("bytes      : %d\n", strlen((string) $raw));

$j    = json_decode((string) $raw, true);
$node = $j[$appid] ?? null;

if ($node === null) {
    fwrite(STDERR, "FATAL: no top-level key for appid {$appid}. Top-level keys: "
        . implode(',', array_keys((array) $j)) . "\n");
    exit(1);
}

printf("success    : %s\n", var_export($node['success'] ?? null, true));

$data = $node['data'] ?? null;
if (! is_array($data)) {
    fwrite(STDERR, "FATAL: success=true but 'data' is not an array. Keys: "
        . implode(',', array_keys($node)) . "\n");
    exit(1);
}

printf("name       : %s\n", $data['name'] ?? '(none)');
printf("data keys  : %s\n", implode(', ', array_keys($data)));

$req = $data['pc_requirements'] ?? null;
printf("pc_requirements present: %s\n", $req === null ? 'NO' : 'yes');

if (is_array($req)) {
    foreach ($req as $tier => $body) {
        if (! is_array($body)) {
            printf("  [%s] => %s\n", $tier, var_export($body, true));
            continue;
        }
        printf("  [%s] keys: %s\n", $tier, implode(', ', array_keys($body)));
        $html = $body['raw'] ?? null;
        printf("  [%s].raw is %s (%d bytes)\n",
            $tier,
            $html === null ? 'MISSING' : 'present',
            strlen((string) $html)
        );
        if ($html !== null) {
            printf("  [%s].raw first 180 chars: %s\n",
                $tier,
                preg_replace('/\s+/', ' ', substr(strip_tags((string) $html), 0, 180))
            );
        }
    }
}
