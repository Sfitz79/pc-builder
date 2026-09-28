<?php

/**
 * genie-measure-entry-price.php
 *
 * Prints the MEASURED entry price per resolution - the cheapest complete
 * machine we can actually assemble - plus the workable bands the storefront
 * and slider will use. Boss directive 2026-09-28: advertise only what we can
 * deliver, and start the slider where a real build starts.
 *
 * Run: php scripts/genie-measure-entry-price.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(App\Services\AIRecommendationService::class);

echo "=== MEASURED ENTRY PRICE (cheapest COMPLETE buildable machine) ===\n";
echo "all-in, delivery included\n\n";

foreach (['1080P', '1440P', '4K'] as $resolution) {
    $m = $service->entryPriceFor($resolution);

    if ($m === null) {
        printf("%-6s NO COMPLETE BUILD - band must not be advertised\n", $resolution);

        continue;
    }

    printf(
        "%-6s GBP %8.2f  (publish %s)  complete=%s  cpu=%s  gpu=%s\n",
        $resolution,
        $m['price'],
        number_format($m['rounded'], 0),
        $m['complete'] ? 'yes' : 'NO',
        (string) ($m['cpu'] ?? '-'),
        (string) ($m['gpu'] ?? '-')
    );
}

echo "\n=== WORKABLE BANDS (what the storefront will show) ===\n\n";

foreach ($service->workableBands() as $key => $band) {
    printf(
        "%-6s GBP %s - GBP %s  gpu-floor tier %d  measured=%s\n",
        $band['label'],
        number_format($band['min'], 0),
        number_format($band['max'], 0),
        $band['floor_gpu_tier'],
        $band['measured'] ? 'yes' : 'FALLBACK'
    );
}
