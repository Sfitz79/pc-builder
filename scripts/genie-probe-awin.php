<?php
// Genie: probe the Awin feed list with the owner's supplied key.
// NEVER prints the key. Only whether it authenticated, and the shape of the answer.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = config('services.awin.feed_api_key');
$pub = config('services.awin.publisher_id');

echo "publisher_id   : " . var_export($pub, true) . PHP_EOL;
echo "feed key set   : " . (is_string($key) && $key !== '' ? 'yes' : 'NO') . PHP_EOL;
echo "feed key shape : " . (is_string($key) ? strlen($key) . ' chars, uuid=' . (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) ? 'yes' : 'no') : 'n/a') . PHP_EOL;

if (! is_string($key) || $key === '') {
    echo PHP_EOL . "No key - stopping." . PHP_EOL;
    exit(1);
}

$url = rtrim(config('services.awin.feed_base'), '/') . '/datafeed/list/apikey/' . $key;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo PHP_EOL . "feed list HTTP  : " . $code . PHP_EOL;
if ($err) { echo "curl error      : " . $err . PHP_EOL; }
echo "body bytes      : " . (is_string($body) ? strlen($body) : 0) . PHP_EOL;

// A 403 body echoes the key back in the URL it rejected. Strip the key from
// anything we print, so this probe can never leak the secret into a log.
if (is_string($body) && $body !== '') {
    $safe = str_replace($key, '[REDACTED]', $body);
    echo PHP_EOL . "--- response (key redacted) ---" . PHP_EOL;
    echo substr($safe, 0, 1500) . PHP_EOL;
}
