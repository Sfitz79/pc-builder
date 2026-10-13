<?php

/**
 * What is the advertised band floor AFTER the CPU generation floor, versus what
 * production publishes today?
 *
 * WHY THIS MATTERS COMMERCIALLY
 * ----------------------------
 * entryPriceFor() builds the cheapest COMPLETE build from the live catalogue and publishes
 * that as the band floor - "we do 1080p builds from GBP 1040". That promise is only true if
 * the engine can actually assemble a build at that price.
 *
 * Before the CPU generation floor (boss report 2026-10-13), the cheapest 1080p build could
 * be assembled around a Ryzen 5 3600 or a Ryzen 3 3200G - a 2018/2019 part. That build was
 * cheap, so the published floor was low. After the floor, those CPUs cannot be quoted, so the
 * cheapest honest build costs more and the published floor is TOO LOW.
 *
 * That is the opposite failure to a stale price: not an under-quote from old data, but an
 * advertised entry price we cannot honour. Read-only.
 *
 * Usage: php scripts/genie-measure-band-floors.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AIRecommendationService;

$svc = app(AIRecommendationService::class);

echo "CONNECTION: ".config('database.default')."  (production Neon if this reads the same data)\n\n";
echo "PUBLISHED ON PRODUCTION RIGHT NOW (GET https://pctechguy.app/builder/bands)\n";
echo "  1080p  1040 - 1190\n  1440p  1190 - 1630\n  4k     1630 - 3500\n\n";

echo "MEASURED NOW, WITH THE CPU GENERATION FLOOR IN FORCE\n";

foreach (['1080P', '1440P', '4K'] as $band) {
    $row = $svc->entryPriceFor($band);

    if ($row === null) {
        echo "  {$band}: no complete build could be assembled\n";
        continue;
    }

    printf(
        "  %-6s cheapest complete build = GBP %-9s rounded GBP %-8s complete=%s\n",
        $band,
        number_format((float) $row['price'], 2),
        number_format((float) $row['rounded'], 0),
        ! empty($row['complete']) ? 'yes' : 'NO'
    );

    if (! empty($row['components']) && is_array($row['components'])) {
        foreach ($row['components'] as $cat => $c) {
            $name = is_array($c) ? ($c['name'] ?? '') : ($c->name ?? '');
            if ($name !== '' && stripos($name, 'ryzen') !== false) {
                echo "           CPU: {$name}\n";
            }
        }
    }
}

echo "\nIf a measured floor is ABOVE the published band minimum, the advertised entry price\n";
echo "is one we cannot honour and must be corrected before it reaches a customer.\n";
