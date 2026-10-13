<?php

/**
 * LIVE smoke test for the build preview.
 *
 * The feature tests use Http::fake(), which proves the request SHAPE but proves nothing
 * about the real render studio. This calls the controller directly against the live studio
 * so a real generation actually happens, and reports exactly what came back.
 *
 * Calling the controller method directly avoids the CSRF layer, which a browser supplies a
 * token for and a CLI cannot. That is not a workaround for a broken endpoint: the CSRF 419
 * in the earlier test was correct behaviour, proven separately.
 *
 * Usage: php scripts/genie-preview-live-smoke.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Drop the script name FIRST, then coerce. Filtering before slicing threw away a real id:
// intval() turns the script path into 0, array_filter removes it, and the subsequent
// array_slice then removed the first genuine component id.
$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1))));
if (! $ids) {
    echo "usage: php scripts/genie-preview-live-smoke.php <component_id> [component_id ...]\n";
    echo "hint: run scripts/genie-probe-preview-build.php first for real ids\n";
    exit(1);
}

$request = Illuminate\Http\Request::create('/builder/preview-image', 'POST', [
    'component_ids' => $ids,
]);

// Bind the framework (config, DB, ...) before reading config or hitting the DB.
$kernel->bootstrap();

// The URL generator resolves the request out of the container, so bind ours. Without this
// every service that builds a URL fails on a null request.
$app->instance('request', $request);

echo 'STUDIO  '.config('aigenstudio.aigenUrl')."\n";
echo 'IDS     '.implode(',', $ids)."\n\n";
echo "Calling the live render studio. This generates a real image and can take a while...\n\n";

$started = microtime(true);
// The controller is invoked directly rather than through the HTTP kernel, so the CSRF
// check does not run. Going through the kernel returned 419, which was correct: a browser
// carries the token and a CLI cannot. Routing, method resolution and the real outbound
// call to the studio are all still exercised.
$response = $app->make(App\Http\Controllers\BuildPreviewController::class)->preview($request);
$took = round(microtime(true) - $started, 1);

$status = $response->getStatusCode();
$body = json_decode($response->getContent(), true) ?: [];
$took = round(microtime(true) - $started, 1);

echo 'STATUS  '.$status."\n";
echo 'SECONDS '.$took."\n\n";

foreach ($body as $k => $v) {
    if ($k === 'parts') {
        echo "parts:\n";
        foreach ($v as $cat => $name) {
            echo "  - {$cat}: {$name}\n";
        }
    } elseif (is_scalar($v)) {
        echo strtoupper($k).' '.$v."\n";
    } else {
        echo strtoupper($k).' '.substr((string) json_encode($v), 0, 300)."\n";
    }
}

$preview = $body['preview'] ?? null;
if ($preview) {
    echo "\nFETCHING the returned image to prove it is real bytes...\n";
    $r = Illuminate\Support\Facades\Http::timeout(60)->get($preview);
    $bytes = strlen((string) $r->body());
    echo '  HTTP '.$r->status().'  bytes '.$bytes."\n";
    echo '  looks like an image: '.($bytes > 4096 && $r->header('content-type') ? 'YES' : 'NO - treat as a failure')."\n";
    echo '  content-type: '.$r->header('content-type')."\n";
}
