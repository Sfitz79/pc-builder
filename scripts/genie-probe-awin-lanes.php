<?php
// Genie: work out WHICH Awin credential this actually is.
//
// The feed-list endpoint answers 500 for a well-formed-but-unknown key (verified:
// a literal garbage key also returns 500, not 403), so a 500 here means
// "Awin did not recognise this key on this endpoint" and nothing more. A UUID is
// the shape of an Awin Publisher API token, not of a Product Data Feed key, so
// this probes both lanes and reports which one authenticates.
//
// The key is never printed. Every request/response is redacted before output.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = config('services.awin.feed_api_key');
$pub = config('services.awin.publisher_id');

if (! is_string($key) || $key === '') {
    fwrite(STDERR, "no key configured\n");
    exit(1);
}

function req(string $label, string $url, array $headers = []): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = is_string($body) ? $body : '';
    $safe = str_replace([config('services.awin.feed_api_key'), config('services.awin.api_token')], '[REDACTED]', $body);
    $safe = preg_replace('/\s+/', ' ', strip_tags($safe));

    printf("%-34s HTTP %-4s  %5d bytes\n", $label, $code, strlen($body));
    if ($safe !== '' && strlen($safe) < 400) {
        echo "      " . substr($safe, 0, 320) . PHP_EOL;
    }
}

$feed = rtrim(config('services.awin.feed_base'), '/');
$api = rtrim(config('services.awin.api_base'), '/');
$ua = 'Accept: application/json';

echo "=== Lane 1: Product Data Feed (CSV) - key in the URL path ===\n";
req('feed list', $feed.'/datafeed/list/apikey/'.$key);

echo PHP_EOL . "=== Lane 2: Publisher API - UUID as a Bearer token ===\n";
req('publisher profile (bearer)', $api.'/publishers/'.$pub, [$ua, 'Authorization: Bearer '.$key]);

echo PHP_EOL . "=== Control: what an obviously invalid key returns ===\n";
req('feed list (garbage key)', $feed.'/datafeed/list/apikey/not-a-real-key-000');
req('publisher (garbage bearer)', $api.'/publishers/'.$pub, [$ua, 'Authorization: Bearer not-a-real-token-000']);
