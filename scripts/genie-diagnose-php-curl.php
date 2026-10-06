<?php
/**
 * Diagnose why PHP curl cannot fetch an image that curl.exe CAN fetch.
 * The bulk downloader reported only "http 0" for every attempt, which is not an
 * acceptable diagnosis. This prints curl_errno, curl_error, and the PHP/curl
 * build details so the real cause is named rather than guessed at.
 */
echo "PHP           : " . PHP_VERSION . PHP_EOL;
echo "curl loaded   : " . (extension_loaded('curl') ? 'yes' : 'NO') . PHP_EOL;
echo "curl ext ver  : " . (defined('LIBCURL_VERSION') ? curl_version()['version'] : 'n/a') . PHP_EOL;
echo "SSL backend   : " . (defined('LIBCURL_VERSION') ? (curl_version()['ssl_version'] ?? 'unknown') : 'n/a') . PHP_EOL;
echo "cafile        : " . (ini_get('curl.cainfo') ?: '(not set)') . PHP_EOL;
echo "openssl.cafile: " . (ini_get('openssl.cafile') ?: '(not set)') . PHP_EOL;
echo "open_basedir  : " . (ini_get('open_basedir') ?: '(not set)') . PHP_EOL;
echo PHP_EOL;

$url = 'https://cdna.pcpartpicker.com/static/forever/images/product/318bdf13ecf18f2e9d967c0ce0d61730.jpg';

echo "--- DNS from PHP ---" . PHP_EOL;
$ip = gethostbyname('cdna.pcpartpicker.com');
echo "  gethostbyname : {$ip}" . PHP_EOL;

echo PHP_EOL . "--- curl attempt with verbose errno ---" . PHP_EOL;
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_USERAGENT      => 'pctechguyonline-builder/1.0 (+https://pctechguyonline.com)',
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "  errno   : {$errno}" . PHP_EOL;
echo "  error   : " . ($err ?: '(none)') . PHP_EOL;
echo "  http    : {$code}" . PHP_EOL;
echo "  bytes   : " . strlen((string)$body) . PHP_EOL;
echo "  errno meaning: " . (function_exists('curl_strerror') ? curl_strerror($errno) : 'n/a') . PHP_EOL;
