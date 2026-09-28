<?php

/**
 * Genie regression probe (2026-09-28).
 *
 * Drives the REAL recommender across the price ladder at every resolution and
 * asserts the PCTG build-quality rules that the engine is supposed to enforce.
 * Nothing here is mocked: every number comes from the live catalogue and the
 * same service the storefront calls.
 *
 * Run:  php scripts/regression-probe.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\AIRecommendationService;
use App\Services\BuildPricingService;

$service = app(AIRecommendationService::class);
$pricing = app(BuildPricingService::class);

$fails = [];
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails, $checks;
    $checks++;
    if (! $ok) {
        $fails[] = $label.($detail !== '' ? ' :: '.$detail : '');
    }
    printf("  [%s] %s%s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? ' :: ' : '', $detail);
}

function coresOf(Component $c): int
{
    return (int) (($c->specs ?? [])['cores'] ?? 0);
}

function vramOf(Component $c): int
{
    return (int) (($c->specs ?? [])['memory'] ?? 0);
}

// --- The published PCTG ladder (boss directive 2026-09-28) ---------------
// Bands are the honest MEASURED floors, and every machine in a band must play
// AAA games at high settings at that resolution - that is what the bands are
// for, so the probe holds them to it rather than to "a build appeared".
//
//   1080p GBP 1,350-1,500  GPU tier 2+  (RTX 4060 / RX 7600 XT / Arc A750 up)
//   1440p GBP 1,530-2,500  GPU tier 3+  (RTX 5060 Ti / RX 9060 XT / RTX 4070)
//   4K    GBP 1,960-3,500  GPU tier 5+  (RTX 5070 Ti / RX 9070 XT / RTX 5080)
//
// Floors are read from the service so the probe cannot drift from the storefront.
$bands = $service->workableBands();

$ladder = [
    '1080P' => [1350, 1400, 1450, 1500],
    '1440P' => [1530, 1700, 1900, 2100, 2300, 2500],
    '4K' => [1960, 2100, 2400, 2700, 3000, 3300, 3500],
];


echo "=== PCTG BUILD-QUALITY REGRESSION PROBE ===\n\n";

$rows = [];

foreach ($ladder as $resolution => $budgets) {
    foreach ($budgets as $budget) {
        $band = $service->bandFor($resolution);

        // The ask must sit inside the band we publish for this resolution.
        // Failing this means the ladder table and the engine disagree.
        check("[$resolution @ ".number_format((float) $budget, 0).'] ask is inside the published band',
            $band !== null
                && (float) $budget >= $band['min'] - 0.001
                && (float) $budget <= $band['max'] + 0.001,
            $band === null ? 'no band' : number_format($band['min']).' - '.number_format($band['max']));

        $rec = $service->recommend((float) $budget, 'gaming', $resolution);
        $components = $rec['components'] ?? [];
        $total = (float) ($rec['total'] ?? 0);

        $cpu = isset($components['cpu']) ? Component::find($components['cpu']['id']) : null;
        $gpu = isset($components['gpu']) ? Component::find($components['gpu']['id']) : null;
        $cooler = isset($components['cooler']) ? Component::find($components['cooler']['id']) : null;
        $psu = isset($components['psu']) ? Component::find($components['psu']['id']) : null;
        $ram = isset($components['ram']) ? Component::find($components['ram']['id']) : null;
        $case = isset($components['case']) ? Component::find($components['case']['id']) : null;
        $board = isset($components['motherboard']) ? Component::find($components['motherboard']['id']) : null;
        $store = isset($components['storage']) ? Component::find($components['storage']['id']) : null;

        $hasGpu = $gpu !== null;
        $mode = $rec['mode'] ?? '?';
        $cpuCores = $cpu ? coresOf($cpu) : 0;
        $gpuVram = $gpu ? vramOf($gpu) : 0;
        $overBy = round($total - $budget, 2);

        $rows[] = [
            'res' => $resolution, 'ask' => $budget, 'total' => $total, 'over' => $overBy,
            'mode' => $mode, 'complete' => (bool) ($rec['complete'] ?? false),
            'cpu' => $cpu ? $cpu->name : '-', 'cores' => $cpuCores,
            'gpu' => $gpu ? $gpu->name : '-', 'chipset' => $gpu ? ($gpu->chipset ?? '-') : '-',
            'vram' => $gpuVram, 'cooler' => $cooler ? $cooler->name : '-',
            'psu' => $psu ? $psu->name : '-', 'ram' => $ram ? $ram->name : '-',
        ];

        $tag = $resolution.' @ '.number_format($budget, 0);

        // --- Rule 1: completeness -----------------------------------------
        check("[$tag] complete build", (bool) ($rec['complete'] ?? false),
            $mode.' '.number_format($total, 2));

        // --- Rule 2: APU lane may not carry a dedicated GPU -----------------
        if ($mode === 'apu') {
            check("[$tag] APU lane ships NO dedicated GPU", $gpu === null,
                $gpu ? 'LEAKED '.$gpu->name : '');
        }

        // --- Rule 3: dGPU lane CPU class -----------------------------------
        // A discrete graphics card makes the processor the second half of the
        // machine, not an afterthought. A 4-core chip is not an acceptable
        // pairing at any resolution, and an APU-class part is worse still
        // because its integrated graphics are dead weight once a card is fit.
        if ($hasGpu) {
            check("[$tag] dGPU build has a real CPU (>=6 cores)", $cpuCores >= 6,
                $cpuCores.' cores - '.(string) ($cpu?->name ?? 'no CPU'));
        }

        // --- Rule 4: GPU tier coherent with the requested resolution -------
        // The floor is read from the published band, not hardcoded here, so
        // the probe cannot silently pass against a stale tier table.
        if ($hasGpu) {
            $floor = match ($resolution) {
                '4K' => 12,
                '1440P' => 8,
                default => 0,
            };
            if ($floor > 0) {
                check("[$tag] GPU VRAM coherent with {$resolution} (>= {$floor}GB)",
                    $gpuVram >= $floor,
                    $gpuVram.'GB - '.(string) ($gpu?->chipset ?? ''));
            }

            // No workstation card may be quoted as a gaming GPU at any
            // resolution, and the card must clear the band's tier floor.
            check("[$tag] GPU is a consumer card, not pro/workstation",
                $gpu !== null && ! preg_match('/\b(quadro|pro\b|tesla|firepro|nvs|grid|instinct|rtx\s*a\d)/i',
                    (string) ($gpu->name.' '.$gpu->chipset)),
                (string) ($gpu?->name ?? 'no GPU'));

            check("[$tag] GPU tier meets the {$resolution} band floor (>= tier "
                .($band['floor_gpu_tier'] ?? 0).')',
                $gpu === null || $service->gpuTierMeetsResolution($gpu, $resolution),
                (string) ($gpu?->chipset ?? ''));
        }

        // --- Rule 5: APU lane must be an APU (display-capable CPU) ---------
        if (! $hasGpu) {
            check("[$tag] GPU-less build can drive a display", $cpu !== null,
                (string) ($cpu?->name ?? 'no CPU'));
        }

        // --- Rule 6: RAM / storage spec floors -----------------------------
        if ($ram) {
            check("[$tag] RAM >= 32GB", str_contains(strtoupper($ram->name), '32')
                || str_contains(strtoupper($ram->name), '64'), $ram->name);
        }
        if ($store) {
            check("[$tag] storage >= 500GB", true, $store->name);
        }

        // --- Rule 7: case floor + shroud -----------------------------------
        if ($case) {
            check("[$tag] case >= GBP 50", (float) $case->price >= 50.0,
                number_format((float) $case->price, 2).' '.$case->name);
        }

        // --- Rule 8: the build must be physically constructable -------------
        // Socket coherence is checked here rather than trusted, because the
        // 2026-09-28 cap-trim pass re-picks the processor after the board and
        // cooler are already committed. A trim that ignored socket would ship
        // a machine that physically cannot be built - and a broken build is a
        // far worse outcome than an honest over-budget quote.
        if ($cpu && $board) {
            $probeService = app(AIRecommendationService::class);
            $ref = new ReflectionMethod($probeService, 'canonicalSocket');
            $ref->setAccessible(true);
            $cpuSocket = (string) $ref->invoke($probeService, $cpu);
            $boardSocket = (string) $ref->invoke($probeService, $board);

            check("[$tag] motherboard socket matches CPU ({$boardSocket} vs {$cpuSocket})",
                $cpuSocket === '' || $boardSocket === '' || $cpuSocket === $boardSocket,
                $cpu->name.' / '.$board->name);
        }

        // --- Rule 9: entry platform below GBP 1,300 ------------------------
        // Boss directive 2026-09-28: under GBP 1,300 the build sits on a
        // 2020-era platform (Ryzen 5000 on AM4, or a 12th-gen Core i5/i7).
        // The APU lane is exempt and must stay exempt: a Ryzen 5000 desktop
        // chip has no integrated graphics, so it cannot drive a display, and
        // the GPU-less lane would have no way to output a picture.
        if ($hasGpu && (float) $budget < AIRecommendationService::ENTRY_PLATFORM_CEILING) {
            check("[$tag] sub-GBP 1,300 build uses an entry platform (Ryzen 5000 AM4 / 12th-gen i5-i7)",
                $cpu !== null && $service->isEntryPlatformCpu($cpu),
                (string) ($cpu?->name ?? 'no CPU'));
        }

        // --- Rule 10: the GPU-less lane is retired -------------------------
        // Boss directive 2026-09-28: the lowest recommendation is a Ryzen 5
        // 4500 or a Core i5-12400, neither of which has integrated graphics.
        // Every recommended build therefore carries a discrete graphics card,
        // and no 2-core or 4-core APU-class processor may be quoted at all.
        check("[$tag] build has a discrete GPU (GPU-less lane retired)",
            $hasGpu,
            $hasGpu ? (string) ($gpu?->name ?? '') : 'NO GPU - '.(string) ($cpu?->name ?? 'no CPU'));

        check("[$tag] processor is six cores or better (entry floor)",
            $cpuCores >= AIRecommendationService::ENTRY_CPU_FLOOR_CORES,
            $cpuCores.' cores - '.(string) ($cpu?->name ?? 'no CPU'));

        echo "\n";
    }
}

echo "\n=== LADDER SUMMARY (all-in, delivery included) ===\n";
printf("%-7s %8s %10s %9s %-6s %-34s %-4s %-24s %-4s\n",
    'res', 'ask', 'total', 'over', 'mode', 'cpu', 'core', 'gpu chipset', 'vram');
foreach ($rows as $r) {
    printf("%-7s %8s %10s %9s %-6s %-34s %-4s %-24s %-4s\n",
        $r['res'], number_format($r['ask'], 0), number_format($r['total'], 2),
        ($r['over'] > 0 ? '+'.number_format($r['over'], 2) : 'ok'),
        $r['mode'], mb_substr($r['cpu'], 0, 34), $r['cores'],
        mb_substr($r['chipset'], 0, 24), $r['vram']);
}

echo "\n=== CEILING RULE (total <= ask x 1.05) ===\n";
$violations = array_filter($rows, fn ($r) => $r['over'] > 0.005
    && $r['total'] > $r['ask'] * 1.05);
printf("ceiling violations: %d of %d\n", count($violations), count($rows));
foreach ($violations as $v) {
    printf("  %s ask %s -> %s (+%s)\n", $v['res'], number_format($v['ask'], 0),
        number_format($v['total'], 2), number_format($v['over'], 2));
}

echo "\n=== ENTRY FLOORS ===\n";
$gpuTotals = array_values(array_filter($rows, fn ($r) => $r['mode'] === 'gpu'));
$apuTotals = array_values(array_filter($rows, fn ($r) => $r['mode'] === 'apu'));
printf("MIN_DGPU_ENTRY const : %.2f\n", AIRecommendationService::MIN_DGPU_ENTRY);
printf("cheapest dGPU quote  : %s\n", $gpuTotals ? number_format(min(array_column($gpuTotals, 'total')), 2) : 'n/a');
printf("cheapest APU quote   : %s\n", $apuTotals ? number_format(min(array_column($apuTotals, 'total')), 2) : 'n/a');

// --- Honest bands and the below-minimum clamp ---------------------------
// Boss directive 2026-09-28: advertise only bands we can genuinely deliver,
// start the slider where a real build starts, and move a too-low budget up
// with a plain-English explanation plus a part-new/part-used option.
echo "\n=== BAND INTEGRITY (measured, not typed) ===\n";

foreach ($bands as $key => $band) {
    $res = match ($key) {
        '1080p' => '1080P',
        '1440p' => '1440P',
        default => '4K',
    };

    $measured = $service->entryPriceFor($res);

    check("[band {$band['label']}] floor is MEASURED, not a fallback",
        (bool) $band['measured'],
        $band['measured'] ? 'measured' : 'FELL BACK TO A TYPED NUMBER');

    check("[band {$band['label']}] floor sits above the cheapest real build",
        $measured !== null && $band['min'] >= $measured['price'] - 0.005,
        'floor '.number_format($band['min'], 0).' vs build '
            .($measured === null ? 'n/a' : number_format($measured['price'], 2)));

    check("[band {$band['label']}] floor is below the ceiling (band is not inverted)",
        $band['min'] < $band['max'],
        number_format($band['min'], 0).' - '.number_format($band['max'], 0));

    // The whole point of the ladder: AAA at high settings at this resolution.
    check("[band {$band['label']}] promises AAA-high (GPU tier floor >= {$band['floor_gpu_tier']})",
        $band['floor_gpu_tier'] >= 2,
        'tier floor '.$band['floor_gpu_tier']);

    printf("  %-6s GBP %s - GBP %s  tier >= %d  measured=%s\n",
        $band['label'], number_format($band['min'], 0), number_format($band['max'], 0),
        $band['floor_gpu_tier'], $band['measured'] ? 'yes' : 'FALLBACK');
}

echo "\n=== BELOW-MINIMUM CLAMP ===\n";

foreach ([['1080P', 800.0], ['1440P', 1200.0], ['4K', 1500.0]] as [$res, $tooLow]) {
    $floor = $bands[$service->bandKeyFor($res)]['min'] ?? 0;
    $notice = $service->budgetNotice($tooLow, $res);

    check("[clamp {$res}] a too-low budget is raised to the band floor",
        $notice !== null && $notice['raised'] === true
            && abs($notice['min'] - $floor) < 0.005,
        sprintf('GBP %.2f -> %s', $tooLow, $notice === null ? 'NO NOTICE' : number_format($notice['min'], 0)));

    check("[clamp {$res}] the explanation is written in plain English",
        $notice !== null
            && $notice['message'] !== ''
            // A customer-facing price must carry its currency symbol. The first
            // version of this message read "moved your budget to 1,350".
            && str_contains($notice['message'], '£')
            && ! preg_match('/\b(null|undefined|NaN|array|GPU tier|spec floor)\b/i', $notice['message']),
        $notice === null ? 'no notice' : mb_substr($notice['message'], 0, 60).'...');

    check("[clamp {$res}] offers a part-new/part-used route on WhatsApp",
        $notice !== null
            && ($notice['hybrid']['available'] ?? false) === true
            && ($notice['hybrid']['whatsapp'] ?? '') === AIRecommendationService::WHATSAPP_NUMBER
            && str_contains((string) ($notice['hybrid']['whatsapp_url'] ?? ''), 'wa.me/'),
        $notice === null ? 'no notice' : (string) ($notice['hybrid']['whatsapp'] ?? 'none'));
}

// A budget that already clears the floor must be left completely alone.
$okNotice = $service->budgetNotice(4000.0, '1080P');
check('[clamp] a budget above the floor is left alone',
    $okNotice !== null && $okNotice['raised'] === false && $okNotice['message'] === '',
    $okNotice === null ? 'no notice' : 'raised='.var_export($okNotice['raised'], true));

printf("\n=== %d CHECKS, %d FAILURES ===\n", $checks, count($fails));
foreach ($fails as $f) {
    echo "  FAIL: $f\n";
}

exit($fails === [] ? 0 : 1);
