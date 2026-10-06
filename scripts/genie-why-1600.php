<?php
// Why did the probe fall back to the 256p thumbnail when the 1600px URL works?
// Test the exact URL curl.exe proved good, through PHP curl, and print the
// detail (code, content-type, size, redirect count, error) instead of guessing.
$u = 'http://cdna.pcpartpicker.com/static/forever/images/product/7e2d17b7b777f8b56e1b5119aa97be20.1600.jpg';

$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
]);
$b = curl_exec($ch);
$i = curl_getinfo($ch);
$err = curl_error($ch);
curl_close($ch);

printf("http_code : %d\n", (int) $i['http_code']);
printf("ctype     : %s\n", (string) $i['content_type']);
printf("size      : %s\n", is_string($b) ? strlen($b) : 'FALSE');
printf("redirects : %d\n", (int) $i['redirect_count']);
printf("curl error: %s\n", $err);
printf("passes gate (>3000 && contains 'image'): %s\n",
    (is_string($b) && strlen($b) > 3000 && str_contains((string) $i['content_type'], 'image')) ? 'YES' : 'NO');

// And the thumbnail, for comparison, to see whether the host behaves differently.
$u2 = 'http://cdna.pcpartpicker.com/static/forever/images/product/7e2d17b7b777f8b56e1b5119aa97be20.256p.jpg';
$ch2 = curl_init($u2);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0']);
$b2 = curl_exec($ch2);
$i2 = curl_getinfo($ch2);
curl_close($ch2);
printf("\nthumb http_code: %d  size: %s\n", (int) $i2['http_code'], is_string($b2) ? strlen($b2) : 'FALSE');
