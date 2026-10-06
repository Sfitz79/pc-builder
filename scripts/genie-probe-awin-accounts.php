<?php
// Genie: verify the owner's UUID against the CORRECTLY documented Awin endpoints.
//
// The earlier 404s were my own bad paths (/v2/publishers/... does not exist).
// Awin's documented Publisher API is unversioned off api.awin.com, the token is
// USER-level (not tied to one account), and the canonical first call is:
//
//   GET /accounts?type=publisher
//   GET /accounts?type=advertiser
//   GET /publishers/{publisherId}/programmes?relationship=joined
//
// Awin throttles to 20 calls/minute per user, so this probe is deliberately
// small and paced. The token is never printed - Awin echoes it in 401 bodies.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** The token, held globally so the redaction below can never miss it. */
function awin_token(): string
{
    return (string) config('services.awin.feed_api_key');
}

function call(string $label, string $url, array $headers, int $show = 700): void
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

    $safe = str_replace(awin_token(), '[REDACTED]', is_string($body) ? $body : '');
    printf("%-44s HTTP %-4s %7d b\n", $label, $code, strlen($safe));

    if ($safe !== '') {
        echo '      ' . substr($safe, 0, $show) . "\n";
    }
    sleep(4); // stay well inside the 20 calls/min throttle
}

$token = awin_token();
$pub = config('services.awin.publisher_id');
$api = rtrim(config('services.awin.api_base'), '/');

if ($token === '') {
    fwrite(STDERR, "no token configured\n");
    exit(1);
}

$h = ['Accept: application/json', 'Authorization: Bearer '.$token];

echo "=== 1. Which accounts can this token reach? ===\n";
call('GET /accounts?type=publisher', "$api/accounts?type=publisher", $h);
call('GET /accounts?type=advertiser', "$api/accounts?type=advertiser", $h, 300);

echo "\n=== 2. Publisher {$pub} profile ===\n";
call("GET /publishers/{$pub}", "$api/publishers/$pub", $h, 400);

echo "\n=== 3. Joined programmes (the advertiser set we can price) ===\n";
call('GET /publishers/{$pub}/programmes?relationship=joined',
    "$api/publishers/$pub/programmes?relationship=joined&countryCode=GB", $h, 400);
