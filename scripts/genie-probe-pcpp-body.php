<?php
// The PCPP probe returned HTTP 200 with a 1208-char body titled "Unavailable"
// and NO Cloudflare tell-tales. Byparr is healthy, so the block is upstream and
// of an unknown kind. Guessing at it wastes time - read the body.
//
// This also separates two very different diagnoses:
//   (a) PCPP geo-blocks UK/datacentre IPs  -> a proxy with UK egress could work
//   (b) PCPP has retired/blocked the scraper path -> no proxy helps, and the
//       PCPP lane is permanently dead as an evidence source
require __DIR__ . '/../vendor/autoload.php';

$byparr = getenv('BYPARR_URL') ?: 'http://127.0.0.1:8191';
$url = 'https://uk.pcpartpicker.com/products/internal-hard-drive/';

$ch = curl_init("{$byparr}/v1");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['url' => $url, 'maxTimeout' => 60000]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
]);
$res = curl_exec($ch);
curl_close($ch);

$j = json_decode($res, true);
$html = $j['solution']['response'] ?? '';

echo "=== solution keys ===\n";
foreach (($j['solution'] ?? []) as $k => $v) {
    printf("  %-14s %s %s\n", $k, gettype($v), is_string($v) ? '(' . strlen($v) . ' chars)' : '');
}

echo "\n=== status / message / responseCode ===\n";
echo '  status: ' . ($j['status'] ?? '?') . '   message: ' . ($j['message'] ?? '?') . "\n";
echo '  responseCode: ' . ($j['solution']['responseCode'] ?? '?') . "\n";

echo "\n=== FULL BODY (" . strlen((string) $html) . " chars) ===\n";
echo preg_replace('/\n{3,}/', "\n\n", (string) $html) . "\n";
