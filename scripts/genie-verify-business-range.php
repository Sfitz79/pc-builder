<?php

/**
 * READ-ONLY. What does the SME range actually resolve to, against the REAL
 * catalogue?
 *
 * For every business size and system type, prints whether we can build it and,
 * when we cannot, the reason - naming the missing part rather than hiding the
 * range. This is the report to read before believing the SME range is ready.
 *
 * Nothing is written.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\BusinessCapabilityGate;
use App\Services\BusinessSystemType;

echo 'db            : '.config('database.default').' ('.DB::connection()->getDriverName().")\n";

$businessTagged = App\Models\Component::query()
    ->active()->whereNotNull('segments')->where('segments', 'like', '%"business"%')->count();
echo 'business cat. : '.$businessTagged." tagged parts\n";
echo 'builder RAM floor : '.App\Services\AIRecommendationService::MIN_RAM_GB."GB\n\n";

$gate = app(BusinessCapabilityGate::class);

echo "CAPABILITY INVENTORY (measured against the live catalogue)\n";
foreach ($gate->inventory() as $capability => $held) {
    printf("  %-22s %s\n", $capability, $held ? 'held' : 'NOT STOCKED');
}
echo "\n";

$st = app(BusinessSystemType::class);
$buildable = 0;
$total = 0;

foreach ($st->bySize() as $size) {
    echo str_repeat('-', 78)."\n";
    echo '  '.$size['label'].'  ('.($size['headcount'] ?? '').")\n";
    echo str_repeat('-', 78)."\n";

    foreach ($size['systems'] as $system) {
        $d = $st->find($system['slug']);
        $band = $d['band'];
        $total++;

        if ($d['buildable']) {
            $buildable++;
        }

        printf(
            "  %-30s %-24s %s\n",
            $system['slug'],
            $system['job'],
            $d['buildable'] ? 'BUILDABLE' : 'not stocked'
        );
        printf(
            "      GBP %s-%s   RAM %dGB needed   workload: %s   our entry build: %s\n",
            number_format($band[0]),
            number_format($band[1]),
            $d['minRamGb'],
            $system['workload'] ?? '-',
            $d['builder_entry_price'] === null
                ? 'unmeasured'
                : 'GBP '.number_format($d['builder_entry_price'], 0)
        );


        if (! $d['buildable']) {
            echo '      -> '.$d['unavailable_reason']."\n";
        }

        foreach ($d['advisory'] as $note) {
            echo '      note: '.$note."\n";
        }
    }
    echo "\n";
}

printf("SUMMARY: %d of %d system types buildable from the catalogue today.\n", $buildable, $total);

echo "\nNO WRITES PERFORMED - read-only.\n";
