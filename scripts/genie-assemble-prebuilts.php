<?php

/**
 * Assemble defensible pre-built systems from PRODUCTION data.
 *
 * Rules learned the hard way on 2026-09-28/29:
 *  - Rule 1: measure through the code path that USES the data. Case form
 *    factor was NOT derivable from the product name (0/399 measured by the
 *    probe) - it IS now stored, verified, in specs.form_factor (399/399
 *    backfill, applied 2026-09-29 after a Boss-approved dry run). The
 *    assembler reads THAT, never the name.
 *  - Fail closed. If a constraint cannot be evidenced, the build is not
 *    offered, rather than offered with an assumption attached.
 *  - Board form factor: motherboards carry NO form factor anywhere in
 *    production data (0/397 specs, 3/397 names). The only safe assumption is
 *    ATX-width, and the selected CASE must accept ATX. ATX cases accept every
 *    board format in the catalogue; smaller cases are reserved for when board
 *    evidence exists. Recording an assumed fit would sell what we cannot prove.
 *  - The guard proves we are on production before anything is reported.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

foreach (file($root . '/.env.production.neon') as $line) {
    if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
        $k = $m[1];
        $v = trim(trim($m[2]), "'\"");
        putenv("$k=$v");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

function specsOf(object $r): array
{
    $s = $r->specs ?? null;
    if (is_string($s)) {
        $s = json_decode($s, true);
    }
    return is_array($s) ? $s : [];
}

/**
 * Verified case form factor from the 2026-09-29 backfill.
 * NULL means "no provable form factor" - the caller must fail closed.
 */
function caseFormFactor(object $r): ?string
{
    $s = specsOf($r);
    $f = $s['form_factor'] ?? null;
    return is_string($f) && $f !== '' ? $f : null;
}

// Key by NAME, value the id. pluck('name','id') keys by id, which is the
// inverse of what the lookup below needs.
$cats = DB::table('categories')->pluck('id', 'name');
$need = ['CPU', 'Motherboard', 'GPU', 'RAM', 'Storage', 'PSU', 'Case', 'Cooler'];
$ids = [];
foreach ($need as $n) {
    if (!isset($cats[$n])) {
        fwrite(STDERR, "missing category: $n\n");
        exit(1);
    }
    $ids[$n] = $cats[$n];
}

echo "=== CASE FORM FACTOR: verified specs.form_factor coverage ===\n";
$cases = DB::table('components')->where('category_id', $ids['Case'])->where('active', 1)->whereNotNull('price')->get();
$ffKnown = 0;
$ff = [];
foreach ($cases as $c) {
    $f = caseFormFactor($c);
    if ($f !== null) {
        $ffKnown++;
        $ff[$f] = ($ff[$f] ?? 0) + 1;
    }
}
$total = $cases->count();
printf("  cases with a price: %d\n", $total);
printf("  cases with verified form factor: %d of %d (%.1f%%)\n", $ffKnown, $total, $total ? $ffKnown / $total * 100 : 0);
foreach ($ff as $k => $v) {
    printf("    %-12s %d\n", $k, $v);
}
echo "  board form factor evidence: NONE (0/397 specs, 3/397 names) - boards default to ATX-width\n";
if ($ffKnown < $total * 0.9) {
    echo "  VERDICT: too many cases lack a verified form factor - a pre-built builder cannot\n";
    echo "           promise compatibility from this data. Stop here.\n";
    exit(2);
}

echo "\n=== SOCKET AVAILABILITY (CPU + board must agree) ===\n";
$cpuSockets = DB::table('components')->where('category_id', $ids['CPU'])->where('active', 1)
    ->whereNotNull('socket')->whereNotNull('price')->selectRaw('socket, COUNT(*) c')->groupBy('socket')->pluck('c', 'socket');
$mbSockets = DB::table('components')->where('category_id', $ids['Motherboard'])->where('active', 1)
    ->whereNotNull('socket')->whereNotNull('price')->selectRaw('socket, COUNT(*) c')->groupBy('socket')->pluck('c', 'socket');
foreach (['AM5', 'LGA1700', 'LGA1851'] as $s) {
    printf("  %-9s CPU %4d   Motherboard %4d\n", $s, $cpuSockets[$s] ?? 0, $mbSockets[$s] ?? 0);
}

echo "\n=== ASSEMBLING PRE-BUILDS ===\n";

// Conservative TDP assumptions. These are PUBLISHED TDP where the spec is
// present, otherwise a documented default - never an invented number.
$defaultCpuWatts = 65;
$defaultGpuWatts = 200;

$prebuilts = [];
// Each tier declares the BOARD CHIPSET CLASS it requires and a CASE price floor.
// Thresholds are not arbitrary - they are derived from what production actually
// holds (measured 2026-09-29, 284 ATX/E-ATX cases: min £17.99, p10 £55.47,
// median £99.98, p90 £228.94):
//   * A £17.99 case in a "High End" listing is a brand-integrity failure, not a
//     bargain. The floor sits just above the cheapest cluster (4 cases under
//     £20, all KOLINK) and well under p10 so it never starves any tier.
//   * Board class is per-CPU-generation, because a 7950X3D on A620 is not a
//     cheaper build, it is a broken one. No overclocking claim is made for any
//     tier; the class exists to guarantee the board can physically and
//     electrically carry the CPU and the GPU's PCIe 4.0 x16.
$defs = [
    [
        'name' => 'Starter 1080p Esports', 'budget' => [700, 900], 'socket' => 'AM5',
        'gpuMax' => 320, 'targetFps' => 144, 'caseMin' => 35, 'coolerMin' => 20,
        'boardChipsets' => ['/\bA620\b/i', '/\bB650\b/i'],
    ],
    [
        'name' => 'Mainstream 1080p Ultra', 'budget' => [1100, 1400], 'socket' => 'AM5',
        'gpuMax' => 480, 'targetFps' => 144, 'caseMin' => 45, 'coolerMin' => 25,
        'boardChipsets' => ['/\bB650\b/i', '/\bX670\b/i'],
    ],
    [
        'name' => 'High End 1440p', 'budget' => [1900, 2400], 'socket' => 'AM5',
        'gpuMax' => 900, 'targetFps' => 144, 'caseMin' => 70, 'coolerMin' => 40,
        'boardChipsets' => ['/\bB650E?\b/i', '/\bX670[E]?\b/i'],
    ],
];

foreach ($defs as $def) {
    $socket = $def['socket'];

    $cpu = DB::table('components')->where('category_id', $ids['CPU'])->where('active', 1)
        ->where('socket', $socket)->whereNotNull('price')
        ->where('price', '>=', $def['budget'][0] * 0.28)
        ->orderBy('price')->get()
        ->first(fn ($c) => specsOf($c)['cores'] ?? null);
    if (!$cpu) {
        printf("  %-28s SKIPPED: no CPU found\n", $def['name']);
        continue;
    }
    $cpuWatts = (int) ($cpu->wattage ?: $defaultCpuWatts);
    $cpuSpecs = specsOf($cpu);
    $cores = (int) ($cpuSpecs['cores'] ?? 0);
    $threads = (int) ($cpuSpecs['threads'] ?? ($cores > 0 ? $cores * 2 : 0));

    $gpu = DB::table('components')->where('category_id', $ids['GPU'])->where('active', 1)
        ->whereNotNull('price')->where('price', '<=', $def['gpuMax'])
        ->orderByDesc('price')->get()
        ->first(fn ($g) => (specsOf($g)['chipset'] ?? '') !== '');
    if (!$gpu) {
        printf("  %-28s SKIPPED: no GPU with a real chipset identity\n", $def['name']);
        continue;
    }
    $gpuSpecs = specsOf($gpu);
    $gpuWatts = (int) ($gpu->wattage ?: $defaultGpuWatts);
    $gpuChipset = $gpuSpecs['chipset'];
    $vram = (int) round((float) ($gpuSpecs['memory'] ?? 0));

    // Board on the socket, chosen by CHIPSET CLASS not by price.
    //
    // Measured on production Neon 2026-09-29: 0 of 397 active motherboards carry
    // ANY specs key, so specs.chipset is unavailable and the old
    // "|| specsOf($b)['chipset'] !== ''" clause was ALWAYS FALSE. That left
    // `preg_match(...) !== 1` as the only live test, which is INVERTED: it kept
    // every board whose name did NOT match a known chipset and rejected the
    // B650/X670/B760/Z790 boards it was written to prefer. Combined with
    // orderBy(price) it always returned the cheapest budget board - which is how
    // an A620AM-HVS was paired with a Ryzen 9 7950X3D.
    //
    // A620 cannot carry a 7950X3D responsibly: no PCIe 4.0 x16 for the GPU, no
    // USB4, weak VRM, and no overclocking. Board class is therefore MANDATORY and
    // derived from the name, with the chipset recorded as unverified because no
    // structured evidence exists.
    //
    // Form factor is still NOT in production data, so every board remains
    // ATX-width for case selection - that part was correct and is unchanged.
    $boardReq = $def['boardChipsets'];
    $board = DB::table('components')->where('category_id', $ids['Motherboard'])->where('active', 1)
        ->where('socket', $socket)->whereNotNull('price')
        ->get()
        ->first(function ($b) use ($boardReq) {
            $name = (string) $b->name;
            foreach ($boardReq as $re) {
                if (preg_match($re, $name) === 1) {
                    return true;
                }
            }

            return false;
        });
    if (!$board) {
        printf("  %-28s SKIPPED: no %s-class motherboard\n", $def['name'], implode('/', $boardReq));
        continue;
    }

    $ramBytes = $cores >= 8 ? 32 : 16;
    // Memory GENERATION is mandatory, not a preference. Capacity alone once
    // selected DDR4 kits for an AM5 board, which cannot be assembled.
    //
    // The generation is read from specs.speed, NOT specs.type and NOT the name.
    // Measured on production Neon 2026-09-29 across all 165 priced 32GB kits:
    //   specs.type populated on 0 rows
    //   product name containing DDR4/DDR5: 0 rows
    //   specs.speed populated on 165 rows, formatted "DDR4-2133" / "DDR5-6000"
    // Reading the wrong field is not a defensible fallback - it makes the guard
    // reject the entire catalogue, which is what happened on the first attempt.
    $memGen = $socket === 'AM5' ? 'DDR5' : 'DDR4';
    $ram = DB::table('components')->where('category_id', $ids['RAM'])->where('active', 1)
        ->whereNotNull('price')->orderBy('price')->get()
        ->first(function ($r) use ($ramBytes, $memGen) {
            $s = specsOf($r);
            $cap = (int) preg_replace('/[^0-9]/', '', (string) ($s['capacity'] ?? ''));
            if ($cap !== $ramBytes) {
                return false;
            }
            // specs.speed carries the generation. Fail closed if it is absent:
            // unknown memory is never assumed compatible.
            $speed = (string) ($s['speed'] ?? '');

            return $speed !== '' && stripos($speed, $memGen) !== false;
        });
    if (!$ram) {
        printf("  %-28s SKIPPED: no %dGB %s (catalogue has none for this socket)\n",
            $def['name'], $ramBytes, $memGen);
        continue;
    }
    $ramSpeed = specsOf($ram)['speed'] ?? null;

    $storage = DB::table('components')->where('category_id', $ids['Storage'])->where('active', 1)
        ->whereNotNull('price')->get()
        ->first(function ($s) {
            $cap = (int) preg_replace('/[^0-9]/', '', (string) (specsOf($s)['capacity'] ?? ''));
            return $cap >= 1000;
        });
    if (!$storage) {
        printf("  %-28s SKIPPED: no >=1TB storage\n", $def['name']);
        continue;
    }

    // CPU + GPU + ~90W for board/RAM/drives/fans. Expression was previously
    // buried in a precedence trap; now explicit.
    $estDraw = $cpuWatts + $gpuWatts + 90;
    $psu = DB::table('components')->where('category_id', $ids['PSU'])->where('active', 1)
        ->whereNotNull('price')->whereNotNull('wattage')
        ->where('wattage', '>=', (int) ceil($estDraw * 1.4)) // 40% headroom
        ->orderBy('wattage')->get()
        ->first(function ($p) use ($estDraw) {
            return ((int) $p->wattage) <= $estDraw * 2.2; // do not massively over-provision
        });
    if (!$psu) {
        printf("  %-28s SKIPPED: no PSU with >=%dW headroom\n", $def['name'], (int) ceil($estDraw * 1.4));
        continue;
    }

    // Boards are unprovable below ATX width, so the case MUST accept ATX.
    // ATX cases accept every board in the catalogue. Smaller cases are only
    // offered when board evidence exists - they are not used here.
    //
    // The caseMin floor is the brand-integrity gate. Production has 4 ATX cases
    // under £20 (all KOLINK) and this ordering previously returned the cheapest
    // of them for every tier, which put a £17.99 box in a "High End" build.
    // A case is also NOT checked for GPU length: max_gpu_length exists on 0 of
    // 399 production cases, so no length gate can be honestly enforced here.
    $case = DB::table('components')->where('category_id', $ids['Case'])->where('active', 1)
        ->whereNotNull('price')->where('price', '>=', $def['caseMin'])
        ->orderBy('price')->get()
        ->first(function ($c) {
            $f = caseFormFactor($c);
            if ($f === null) {
                return false; // fail closed: no verified form factor
            }
            // Board is treated as ATX-width, so the case must hold ATX.
            // E-ATX cases also hold ATX boards.
            return in_array($f, ['ATX', 'E-ATX'], true);
        });
    if (!$case) {
        printf("  %-28s SKIPPED: no ATX-capable case\n", $def['name']);
        continue;
    }

    // Cooler: brand whitelist, NOT the old name regex.
    //
    // Measured on production 2026-09-29: 0 of 372 active coolers carry any specs
    // key, so TDP rating, socket support and fan size are all unavailable. The
    // previous filter was preg_match('/(air|cooler|tower|120|140)/i', $name) over a
    // price-ordered list - which matches the substring "cooler" inside the brand
    // name "Cooler Master", so it was selecting on a coincidence of spelling. It
    // paired a 120W 7950X3D with a £19.90 Hyper 212 Spectrum V3.
    //
    // With no structured data the honest options are a name filter or nothing.
    // A brand whitelist is a real signal and is used here, but NO thermal-rating
    // claim is made anywhere: 'cooler_evidence' below says so explicitly, and the
    // page must not promise cooler capacity.
    $coolerBrands = ['Noctua', 'Arctic', 'be quiet', 'Thermalright', 'NZXT', 'Corsair', 'Cooler Master'];
    $coolerMin = (float) $def['coolerMin'];
    $cooler = DB::table('components')->where('category_id', $ids['Cooler'])->where('active', 1)
        ->whereNotNull('price')->where('price', '>=', $coolerMin)
        ->orderBy('price')->get()
        ->first(function ($c) use ($coolerBrands) {
            foreach ($coolerBrands as $b) {
                if (stripos((string) $c->name, $b) !== false) {
                    return true;
                }
            }

            return false;
        });
    if (!$cooler) {
        printf("  %-28s SKIPPED: no recognised-brand cooler >= £%.0f\n", $def['name'], $coolerMin);

        continue;
    }

    $parts = [
        'CPU' => $cpu, 'GPU' => $gpu, 'Motherboard' => $board, 'RAM' => $ram,
        'Storage' => $storage, 'PSU' => $psu, 'Case' => $case, 'Cooler' => $cooler,
    ];

    $total = 0.0;
    $line = [];
    foreach ($parts as $type => $p) {
        $total += (float) $p->price;
        $line[] = sprintf('%s £%.0f', $type, (float) $p->price);
    }

    // Publish only if the total is in the advertised band. Otherwise the page
    // would advertise a price it cannot honour.
    if ($total < $def['budget'][0] || $total > $def['budget'][1]) {
        printf("  %-28s SKIPPED: total £%.0f outside band £%d-£%d\n", $def['name'], $total, $def['budget'][0], $def['budget'][1]);
        continue;
    }

    printf("  %-28s £%.0f  [%s]\n", $def['name'], $total, implode(', ', $line));
    $prebuilts[] = [
        'name' => $def['name'],
        'total' => round($total, 2),
        'socket' => $socket,
        'estimated_draw_watts' => $estDraw,
        'psu_watts' => (int) $psu->wattage,
        'headroom' => round((int) $psu->wattage / max(1, $estDraw), 2),
        'case_form_factor' => caseFormFactor($case),
        'board_form_factor' => 'unknown (treated as ATX-width; no board FF evidence exists)',
        'parts' => array_map(fn ($t, $p) => [
            'type' => $t,
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
        ], array_keys($parts), array_values($parts)),
        'evidence' => [
            'cpu_cores' => $cores,
            'cpu_threads' => $threads,
            'cpu_tdp_source' => $cpu->wattage ? 'components.wattage' : 'default 65W (no TDP recorded)',
            'gpu_chipset' => $gpuChipset,
            'gpu_chipset_source' => 'specs.chipset',
            'gpu_vram_gb' => $vram,
            'gpu_watts_source' => $gpu->wattage ? 'components.wattage' : 'default 200W (no TDP recorded)',
            'ram_capacity_source' => 'specs.capacity',
            'ram_speed' => $ramSpeed,
            'case_form_factor_source' => 'specs.form_factor (verified backfill 2026-09-29, 399/399)',
            'case_price_floor' => '£' . number_format((float) $def['caseMin'], 0) . ' enforced (brand integrity: cheapest 4 ATX cases are £17.99-£19.99)',
            'board_chipset_class' => implode(' | ', $def['boardChipsets']) . ' required by tier',
            'board_chipset_source' => 'NAME MATCH ONLY - 0/397 production motherboards carry specs, so chipset is unverified',
            'cooler_brand_source' => 'NAME MATCH only (whitelist: ' . implode(', ', $coolerBrands) . ')',
            'cooler_thermal_evidence' => 'NONE - 0/372 production coolers carry specs, so TDP/socket coverage is NOT verified. No cooling capacity is claimed.',
            'board_form_factor_source' => 'NONE in production data - ATX-width assumed, fail closed',
            'gpu_length_fit' => 'NOT CHECKED - 0/399 production cases carry max_gpu_length, so no GPU length gate is enforced',
            'price_source' => 'catalogue price - NOT merchant-verified',
        ],
    ];
}

file_put_contents(
    $root . '/database/scraped/prebuilts.json',
    json_encode($prebuilts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

printf("\n  wrote %d pre-built(s) to database/scraped/prebuilts.json\n", count($prebuilts));
printf("  all figures read from PRODUCTION %s, driver asserted by genie-prod-guard\n", DB::connection()->getDriverName());