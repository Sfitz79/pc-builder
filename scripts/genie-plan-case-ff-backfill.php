<?php

/**
 * DRY RUN. Builds the complete case form-factor backfill proposal and writes a
 * plan file. THIS SCRIPT DOES NOT TOUCH PRODUCTION. Writing requires a
 * separate, explicitly invoked script after the plan is reviewed.
 *
 * Rule 2: no destructive/production write without (a) measurement against
 * production data, (b) cross-check of every field the consumer reads, and
 * (c) a dry-run report the owner has read and can defend.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

/**
 * The 8 cases the PCPP slug could not resolve, resolved instead from the
 * MANUFACTURER's own published specification. Each entry records the source
 * that was actually read, because "I know this case" is not evidence.
 *
 * Values are the MAXIMUM board form factor the case supports, which is what a
 * compatibility check needs. Where a retailer claimed more than the
 * manufacturer (Define R5 E-ATX), the manufacturer wins and the value is set
 * to the LOWER figure - under-classifying only costs a compatible combo,
 * over-classifying would sell a build that does not physically fit.
 */
const MANUFACTURER_VERIFIED = [
    'Fractal Design Node 304' => [
        'form_factor' => 'Mini-ITX',
        'supported' => ['Mini-ITX', 'Mini-DTX'],
        'source' => 'fractal-design.com Node 304 product sheet: "Motherboard compatibility: Mini ITX; Mini DTX"',
    ],
    'Fractal Design Node 804' => [
        'form_factor' => 'Micro-ATX',
        'supported' => ['mATX', 'Mini-DTX', 'Mini-ITX'],
        'source' => 'fractal-design.com Node 804 manual: "Motherboard compatibility mATX, Mini-DTX, Mini-ITX"',
    ],
    'Fractal Design Core 1000 USB 3.0' => [
        'form_factor' => 'Micro-ATX',
        'supported' => ['mATX', 'Mini-ITX', 'DTX'],
        'source' => 'fractal-design.com Core 1000 product sheet: "Motherboard compatibility: mATX, Mini ITX, DTX"',
    ],
    'Fractal Design Define R5' => [
        'form_factor' => 'ATX',
        'supported' => ['ATX', 'Micro-ATX', 'Mini-ITX'],
        'source' => 'fractal-design.com Define R5 product sheet: "Motherboard compatibility ATX / Micro ATX / Mini ITX" (a retailer listed E-ATX; manufacturer takes precedence)',
    ],
    'Silverstone SG13' => [
        'form_factor' => 'Mini-ITX',
        'supported' => ['Mini-DTX', 'Mini-ITX'],
        'source' => 'silverstonetek.com SG13: "Mini-DTX / Mini-ITX motherboard & ATX PSU compatible"',
    ],
    'Silverstone FLP01' => [
        'form_factor' => 'E-ATX',
        'supported' => ['SSI-CEB', 'E-ATX', 'ATX', 'Micro-ATX'],
        'source' => 'silverstonetek.com FLP01 press release: "supports motherboards up to the SSI-CEB form factor" (SSI-CEB exceeds E-ATX, so E-ATX is the safe ceiling)',
    ],
    'Silverstone GD09 Type-C' => [
        'form_factor' => 'ATX',
        'supported' => ['SSI-CEB', 'ATX', 'Micro-ATX'],
        'source' => 'silverstonetek.com GD09: "Supports SSI-CEB, ATX, Micro-ATX motherboards"',
    ],
    'Cooler Master N200' => [
        'form_factor' => 'Micro-ATX',
        'supported' => ['Micro-ATX', 'Mini-ITX'],
        'source' => 'coolermaster.com N200: "Motherboard Support Micro ATX / Motherboard Support Mini ITX"',
    ],
];

// Ordered most specific first: "atx" is a substring of "microatx".
const FF_RULES = [
    'Micro-ATX' => ['/\bmicro[-\s]?atx\b/', '/\bm[-\s]?atx\b/', '/\bmatx\b/'],
    'Mini-ITX'  => ['/\bmini[-\s]?itx\b/', '/\bmitx\b/'],
    'E-ATX'     => ['/\be[-\s]?atx\b/', '/\bextended[-\s]?atx\b/'],
    'ITX'       => ['/\bitx\b/'],
    'ATX'       => ['/\batx\b/'],
];

$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$rows = DB::table('components')->where('category_id', $caseId)->where('active', 1)
    ->orderBy('id')->get(['id', 'name', 'source_url', 'specs', 'description']);

$plan = [];
$unresolved = [];
$bySource = ['pcpp-slug' => 0, 'manufacturer' => 0];
$dist = [];

foreach ($rows as $r) {
    $name = (string) $r->name;

    if (isset(MANUFACTURER_VERIFIED[$name])) {
        $v = MANUFACTURER_VERIFIED[$name];
        $plan[] = [
            'id' => $r->id,
            'name' => $name,
            'form_factor' => $v['form_factor'],
            'supported' => $v['supported'],
            'source_kind' => 'manufacturer',
            'source_detail' => $v['source'],
        ];
        $bySource['manufacturer']++;
        $dist[$v['form_factor']] = ($dist[$v['form_factor']] ?? 0) + 1;
        continue;
    }

    $slug = strtolower((string) $r->source_url);
    $ff = null;
    $matched = null;
    foreach (FF_RULES as $label => $patterns) {
        foreach ($patterns as $re) {
            if (preg_match($re, $slug, $m)) {
                $ff = $label;
                $matched = $m[0];
                break 2;
            }
        }
    }

    if ($ff === null) {
        $unresolved[] = ['id' => $r->id, 'name' => $name];
        continue;
    }

    $plan[] = [
        'id' => $r->id,
        'name' => $name,
        'form_factor' => $ff,
        'supported' => null,
        'source_kind' => 'pcpp-slug',
        'source_detail' => 'PCPP product slug token "' . $matched . '" in ' . (string) $r->source_url,
    ];
    $bySource['pcpp-slug']++;
    $dist[$ff] = ($dist[$ff] ?? 0) + 1;
}

arsort($dist);
$total = $rows->count();

echo "================ DRY RUN: case form-factor backfill ================\n";
echo "  NOTHING HAS BEEN WRITTEN TO PRODUCTION\n";
printf("  active case rows            : %d\n", $total);
printf("  proposed updates            : %d\n", count($plan));
printf("  still unresolved            : %d\n", count($unresolved));
printf("  from PCPP slug (free)       : %d\n", $bySource['pcpp-slug']);
printf("  from manufacturer spec      : %d\n", $bySource['manufacturer']);

echo "\n-- proposed distribution --\n";
foreach ($dist as $ff => $n) {
    printf("  %-12s %4d  (%.1f%%)\n", $ff, $n, $n / count($plan) * 100);
}

echo "\n-- the 8 manufacturer-verified rows (the ones that were NOT guesses) --\n";
foreach ($plan as $p) {
    if ($p['source_kind'] === 'manufacturer') {
        printf("  %-34s %-11s %s\n", substr($p['name'], 0, 34), $p['form_factor'], substr($p['source_detail'], 0, 74));
    }
}

echo "\n-- 10 sample slug-derived rows --\n";
$n = 0;
foreach ($plan as $p) {
    if ($p['source_kind'] === 'pcpp-slug' && $n++ < 10) {
        printf("  %-34s %-11s %s\n", substr($p['name'], 0, 34), $p['form_factor'], substr($p['source_detail'], 62, 66));
    }
}

if ($unresolved !== []) {
    echo "\n-- STILL UNRESOLVED (will NOT be written) --\n";
    foreach ($unresolved as $u) {
        printf("  %-44s id=%d\n", $u['name'], $u['id']);
    }
}

// Every existing specs blob is empty for cases, so nothing is overwritten.
$clobber = 0;
foreach ($plan as $p) {
    $row = $rows->firstWhere('id', $p['id']);
    $s = $row->specs;
    if (is_string($s)) {
        $s = json_decode($s, true);
    }
    if (is_array($s) && $s !== []) {
        $clobber++;
    }
}
printf("\n  existing specs blobs that would be merged (not clobbered): %d\n", $clobber);

file_put_contents(
    __DIR__ . '/../database/scraped/case-formfactor-plan.json',
    json_encode(['generated' => date('c'), 'driver' => DB::connection()->getDriverName(), 'plan' => $plan, 'unresolved' => $unresolved], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo "  plan written to database/scraped/case-formfactor-plan.json\n";
echo "===================================================================\n";
