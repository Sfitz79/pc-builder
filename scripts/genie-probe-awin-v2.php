<?php
// Genie: the supplied UUID is a VALID Awin Publisher API token.
//
// Proven by the control in genie-probe-awin-lanes.php: a garbage token returns
// 401 invalid_token, this one returns 404 NOT_FOUND - a different code, so
// authentication passed and the failure is the request path. Awin's API is
// versioned (/v2), and the 404 is what a missing version prefix looks like.
//
// This walks the documented publisher endpoints to confirm the account, the
// advertiser list (which is what the feed lane needs), and the token's scope.
// The token is never printed; Awin echoes it in 401 bodies, so it is redacted.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = config('services.awin.feed_api_key');
$pub = config('services.awin.publisher_id');
$api = rtrim(config('services.awin.api_base'), '/');

$h = ['Accept: application/json', 'Authorization: Bearer '.$key];

function call(string $label, string $url, array $headers, int $maxBytes = 900): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $safe = str_replace(config('services.awin.feed_api_key'), '[REDACTED]', is_string($body) ? $body : '');
    printf("%-46s HTTP %-4s %6d b\n", $label, $code, strlen($safe));

    if ($safe !== '' && strlen($safe) < $maxBytes) {
        echo "      " . substr($safe, 0, $maxBytes) . PHP_EOL;
    } elseif ($safe !== '') {
        echo "      " . substr($safe, 0, 220) . ' ...' . PHP_EOL;
    }
}

echo "=== Awin Publisher API v2 (authenticated) ===\n";
call('v2 publisher profile', "$api/v2/publishers/$pub", $h);
call('v2 publisher list', "$api/v2/publishers", $h);
call('v2 advertisers for this publisher', "$api/v2/publishers/$pub/advertisers", $h);
call('v2 publisher feeds (list)', "$api/v2/publishers/$pub/awinfeeds", $h);

echo PHP_EOL . "=== Control: same endpoints with a garbage token ===\n";
$bad = ['Accept: application/json', 'Authorization: Bearer not-a-real-token-000'];
call('v2 publisher profile (garbage)', "$api/v2/publishers/$pub", $bad);
