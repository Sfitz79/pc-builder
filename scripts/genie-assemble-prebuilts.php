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
/**
 * AMD BOARD GATE.
 *
 * Boss decision: on AMD we build only these chipset families -
 *   AM4: B550 (incl. mATX), X570
 *   AM5: B650 (incl. M/E), X670 (incl. E), B850 (incl. mATX), X870 (incl. E/M/I)
 *
 * Deliberately excluded:
 *   A520, A620  - the entry "*20" chipsets. A620M is GBP 67.50 and A520M is
 *                 GBP 42.99, and both were being fitted: the esports build was
 *                 picking a Gigabyte A520M because it was the cheapest row.
 *   B450, B840  - the older/lower "*40" boards. B450 is PCIe 3.0 across the
 *                 board and predates the generation it is paired with.
 *
 * Family members carry M/E/I suffixes and all count: B550M, B650E, B650M,
 * B850M, X670E, X870E, X870I. A naive numeric suffix test is wrong here and was
 * measured to be - `/\d0$/` style matching keeps B450 (ends "50") while
 * dropping B550M, B650M, B850M and every "-E"/"-I" board, i.e. it keeps the
 * cheapest old board and throws away the mainstream ones. Family-first regexes
 * with the optional suffix inside the pattern avoid that.
 *
 * Applied to AMD sockets only. Intel (LGA1700/LGA1851) is untouched.
 */
const AMD_BOARD_GATE = [
    '/\bB550M?\b/i',
    '/\bX570\b/i',
    '/\bB650[EM]?\b/i',
    '/\bX670[E]?\b/i',
    '/\bB850M?\b/i',
    '/\bX870[EMI]?\b/i',
];

function amdBoardAllowed(string $name, string $socket): bool
{
    // Not an AMD socket: the gate is an AMD policy.
    if (! in_array($socket, ['AM4', 'AM5', 'AM3'], true)) {
        return true;
    }

    foreach (AMD_BOARD_GATE as $rx) {
        if (preg_match($rx, $name) === 1) {
            return true;
        }
    }

    return false;
}

$defs = [
    [
        // ES ONLY, and labelled as such.
        //
        // An APU build drives the display from the CPU. There is no dedicated
        // graphics card, so this is an ESPORTS machine and not a general-purpose
        // one: fine for competitive shooters and MOBAs at 1080p, not for
        // creation, AAA or ray tracing. The name and the storefront copy say so
        // plainly rather than letting "Starter" imply an ordinary small PC.
        //
        // Platform floors, set by the Boss:
        //   - APU must be a 5600G / 5600GT or better. The Ryzen 3 3200G
        //     (GBP 49) and Ryzen 5 3400G (GBP 105) are Zen/Zen+ with 4 cores
        //     and weaker graphics; below the quality bar for a system we sell.
        //     The 5500GT at GBP 112.99 is excluded for the same reason.
        //   - 16GB DDR4-3200 as 2x8GB. DDR4 fixes the socket at AM4. Dual channel
        //     is a hard requirement here because the iGPU shares system memory.
        //
        // B550M rather than the cheaper A520M (GBP 42.99 vs 65.90): A520 is the
        // entry chipset, and holding the same line on boards that we already
        // applied to the CPUs costs GBP 23.
        'name' => 'Esports 1080p APU (No Dedicated GPU)',
        'budget' => [450, 750], 'socket' => 'AM4',
        'apu' => true,
        'apuModels' => ['/\b5600G[TF]?\b/i', '/\b5700G\b/i', '/\b5700GT\b/i'],
        'apuDualChannelRam' => true,
        'tagline' => 'Esports only',
        'notes' => 'Esports and 1080p gaming only. Graphics come from the Ryzen APU - there is no dedicated graphics card. Great for competitive shooters and MOBAs; not suited to video editing, 3D or AAA titles at high settings.',
        'targetFps' => 144, 'caseMin' => 25, 'coolerMin' => 15,
        'boardChipsets' => ['/\bB550M\b/i', '/\bB550\b/i', '/\bX570\b/i'],
    ],
    [
        'name' => 'Mainstream 1080p Ultra', 'budget' => [1100, 1400], 'socket' => 'AM5',
        'gpuMax' => 480, 'gpuFloorTier' => 3, 'targetFps' => 144, 'caseMin' => 45, 'coolerMin' => 25,
        'boardChipsets' => ['/\bB650M?\b/i', '/\bB850M?\b/i', '/\bX670E?\b/i', '/\bX870[E]?\b/i'],
    ],
    [
        // gpuFloorTier 4, not 3. A "High End 1440p" machine carrying a tier-3
        // card is not high end, and the whole point of this tier is that it is
        // the enthusiast 1440p build. Tier 4 is the 1440p-strong class
        // (RTX 5070 / RX 9070 / RTX 5070 Ti and up).
        'name' => 'High End 1440p', 'budget' => [1900, 2400], 'socket' => 'AM5',
        'gpuMax' => 900, 'gpuFloorTier' => 4, 'targetFps' => 144, 'caseMin' => 70, 'coolerMin' => 40,
        'boardChipsets' => ['/\bB650E\b/i', '/\bB850M?\b/i', '/\bX670[E]?\b/i', '/\bX870[E]?\b/i'],
    ],
];

foreach ($defs as $def) {
    $socket = $def['socket'];
    $isApu = ! empty($def['apu']);

    // APU tiers choose their CPU in the graphics block below, because the CPU
    // IS the graphics card there. Running the ordinary CPU selection first
    // would pick a non-G part and then leave $cores/$threads describing a chip
    // that is not in the build, which is what sizes the memory.
    if (! $isApu) {
        $cpu = DB::table('components')->where('category_id', $ids['CPU'])->where('active', 1)
            ->where('socket', $socket)->whereNotNull('price')
            ->where('price', '>=', $def['budget'][0] * 0.28)
            ->orderBy('price')->get()
            ->first(fn ($c) => specsOf($c)['cores'] ?? null);
        if (!$cpu) {
            printf("  %-44s SKIPPED: no CPU found\n", $def['name']);
            continue;
        }
        $cpuWatts = (int) ($cpu->wattage ?: $defaultCpuWatts);
        $cpuSpecs = specsOf($cpu);
        $cores = (int) ($cpuSpecs['cores'] ?? 0);
        $threads = (int) ($cpuSpecs['threads'] ?? ($cores > 0 ? $cores * 2 : 0));
    }

/*
 * GPU SELECTION — BY PERFORMANCE TIER, NOT BY PRICE.
 *
 * THE BUG THIS REPLACES
 * ---------------------
 * The original gate was:
 *
 *   ->where('price', '<=', $def['gpuMax'])
 *   ->orderByDesc('price')->get()
 *   ->first(fn ($g) => (specsOf($g)['chipset'] ?? '') !== '');
 *
 * That takes the most expensive card under a price ceiling. Measured result on
 * production: "High End 1440p" shipped a Sapphire PULSE whose specs.chipset is
 * "Radeon RX 6700 XT" at GBP 899. It won purely because it was the priciest
 * row under GBP 900 that happened to have a chipset string — while real RTX
 * cards sat in the same pool.
 *
 * A price ceiling is not a performance target. Putting an RX 6700 XT in a
 * "High End 1440p" machine is exactly the kind of mislabel that burns a 5-star
 * reputation, so the gate now asks the ENGINE for the card's performance tier
 * and requires the tier the band actually promises.
 *
 * Ordering among qualifying cards is still by price descending, so within the
 * correct tier we take the strongest card the budget affords. gpuMax remains a
 * genuine budget ceiling; it is simply no longer the ONLY constraint.
 */
$gpuFloorTier = $def['gpuFloorTier'] ?? 3;
$gpuService = app(\App\Services\AIRecommendationService::class);

$gpu = null;
$gpuTierSeen = [];

if ($isApu) {
    // APU BUILD: no discrete graphics card at all.
    //
    // The tier floor is skipped deliberately, not overlooked. On a
    // dedicated-GPU build the floor is what stops an RX 6700 XT being sold as
    // "High End 1440p". On an APU build there is no card to grade, and the
    // equivalent guarantee comes from the CPU being a known G-series part.
    //
    // The CPU IS the graphics card here, so it is selected in this block.
    $apuRx = $def['apuModels'] ?? [];
    $minCores = 4;

    $apuCandidates = DB::table('components')
        ->where('category_id', $ids['CPU'])->where('active', 1)
        ->where('socket', $def['socket'])
        ->whereNotNull('price')->where('price', '>', 0)
        ->orderBy('price')->get();

    $cpu = null;
    foreach ($apuCandidates as $cand) {
        $isApuChip = false;
        foreach ($apuRx as $rx) {
            if (preg_match($rx, (string) $cand->name) === 1) {
                $isApuChip = true;
                break;
            }
        }
        if (! $isApuChip) {
            continue;
        }
        if ((int) (specsOf($cand)['cores'] ?? 0) < $minCores) {
            continue;
        }
        $cpu = $cand;
        break;
    }

    if (! $cpu) {
        printf("  %-44s SKIPPED: no G-series APU on %s in catalogue\n", $def['name'], $def['socket']);
        continue;
    }

    $cpuSpecs = specsOf($cpu);
    $cpuWatts = (int) ($cpu->wattage ?: $defaultCpuWatts);
    // These must be the SAME variable names the non-APU path uses, because the
    // RAM sizing below reads $cores to decide 16GB vs 32GB. Setting $cpuCores
    // here instead would leave $cores describing a different chip entirely.
    $cores = (int) ($cpuSpecs['cores'] ?? 0);
    $threads = (int) ($cpuSpecs['threads'] ?? ($cores > 0 ? $cores * 2 : 0));
    // Name goes on its own line. It is padded to 44 because the esports tier's
    // name is 39 characters and was running straight into the literal label.
    printf("  %s\n", $def['name']);
    printf(
        "  %-44s APU:  %s GBP %s on %s (%d cores, integrated graphics, no discrete GPU)\n",
        '', $cpu->name, number_format((float) $cpu->price, 2), $socket, $cores
    );
} else {
    $gpuCandidates = DB::table('components')
        ->where('category_id', $ids['GPU'])
        ->where('active', 1)
        ->whereNotNull('price')
        ->where('price', '>', 0)
        ->where('price', '<=', $def['gpuMax'])
        ->orderByDesc('price')
        ->get();

foreach ($gpuCandidates as $candidate) {
        if (((specsOf($candidate)['chipset'] ?? '') === '')) {
            continue; // unidentifiable card - Rule 6: unknown must never equal permitted
        }

        $model = new \App\Models\Component();
        $model->forceFill([
            'name'     => $candidate->name,
            'chipset'  => $candidate->chipset,
            'specs'    => json_decode((string) $candidate->specs, true) ?: [],
            'wattage'  => $candidate->wattage,
            'price'    => $candidate->price,
            'active'   => true,
        ]);

        $tier = $gpuService->gpuPerformanceTierPublic($model);
        $gpuTierSeen[$tier] = $gpuTierSeen[$tier] ?? 0;
        $gpuTierSeen[$tier]++;

        if ($tier < $gpuFloorTier) {
            continue;
        }

        $gpu = $candidate;
        printf(
            "  %-44s GPU:  %s GBP %s, tier %d (floor %d, cap GBP %s)\n",
            $def['name'], $candidate->name, number_format((float) $candidate->price, 2),
            $tier, $gpuFloorTier, number_format($def['gpuMax'])
        );
        break;
    }

    if (!$gpu) {
        printf(
            "  %-44s SKIPPED: no GPU at tier >= %d under GBP %s (tiers seen: %s)\n",
            $def['name'],
            $gpuFloorTier,
            number_format($def['gpuMax']),
            $gpuTierSeen ? json_encode($gpuTierSeen) : 'none identifiable'
        );
        continue;
    }

    $gpuSpecs = specsOf($gpu);
    $gpuWatts = (int) ($gpu->wattage ?: $defaultGpuWatts);
    $gpuChipset = $gpuSpecs['chipset'];
    $vram = (int) round((float) ($gpuSpecs['memory'] ?? 0));
}

// An APU build has no discrete card: nothing to power beyond the CPU's own
// integrated graphics, which draws from the CPU's TDP rather than a 6-pin rail.
if ($isApu) {
    $gpuWatts = 0;
    $gpuChipset = null;
    $vram = 0;
}

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
    // The per-tier boardChipsets list says which CLASS this tier wants (for
    // example mATX before ATX). The AMD_BOARD_GATE is a separate, global policy
    // about which chipset families we will build at all. Both must pass: a tier
    // list cannot re-admit a chipset the gate forbids.
    //
    // Without the gate the esports tier fitted a Gigabyte A520M at GBP 42.99 -
    // the cheapest row that matched its class list, and an entry "*20" chipset.
    $board = DB::table('components')->where('category_id', $ids['Motherboard'])->where('active', 1)
        ->where('socket', $socket)->whereNotNull('price')->where('price', '>', 0)
        ->orderBy('price')->get()
        ->first(function ($b) use ($boardReq, $socket) {
            $name = (string) $b->name;
            if (! amdBoardAllowed($name, $socket)) {
                return false;
            }
            foreach ($boardReq as $re) {
                if (preg_match($re, $name) === 1) {
                    return true;
                }
            }

            return false;
        });
    if (!$board) {
        printf("  %-44s SKIPPED: no %s-class motherboard\n", $def['name'], implode('/', $boardReq));
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

    // APU floors, from the Boss: 16GB DDR4-3200 as 2x8GB.
    //
    // DUAL CHANNEL IS NOT COSMETIC ON AN APU. The integrated graphics share
    // system memory, so a single-channel kit measurably reduces 1080p frame
    // rates on exactly the esports titles this tier is sold for. A 1x16 kit
    // halves the memory bandwidth available to the iGPU.
    //
    // The module layout is read from the module_config COLUMN, not from specs.
    // Measured on production Neon: RAM specs carry only speed and capacity -
    // there is no specs.modules key at all - while module_config holds strings
    // like "2 x 8GB". Reading a JSON key that does not exist fails the same way
    // the case form factor did (Rule 1: measure the field the consumer reads).
    $apuRamMinSpeed = $isApu ? 3200 : 0;
    $apuRequireDual = (bool) ($isApu && ($def['apuDualChannelRam'] ?? true));

    $ram = DB::table('components')
        ->where('category_id', $ids['RAM'])->where('active', 1)
        ->whereNotNull('price')->where('price', '>', 0)
        ->orderBy('price')->get()
        ->first(function ($r) use ($ramBytes, $memGen, $apuRamMinSpeed, $apuRequireDual) {
            $s = specsOf($r);
            $cap = (int) preg_replace('/[^0-9]/', '', (string) ($s['capacity'] ?? ''));
            if ($cap !== $ramBytes) {
                return false;
            }
            // specs.speed carries the generation. Fail closed if it is absent:
            // unknown memory is never assumed compatible.
            $speed = (string) ($s['speed'] ?? '');
            if ($speed === '' || stripos($speed, $memGen) === false) {
                return false;
            }

            if ($apuRamMinSpeed > 0) {
                // Parse the speed OFF THE END, never by stripping every
                // non-digit. specs.speed is formatted "DDR4-3200", so
                // preg_replace('/[^0-9]/', '', $speed) returns 43200 - the 4
                // from "DDR4" is concatenated with 3200. That made this floor
                // vacuous: a genuine DDR4-2133 kit parses as 42133 and sailed
                // through a >= 3200 test. Strip the generation token first, then
                // read the remaining number.
                $numeric = preg_replace('/DDR[45]/i', '', $speed);
                $mt = (int) preg_replace('/[^0-9]/', '', (string) $numeric);
                if ($mt < $apuRamMinSpeed) {
                    return false;
                }
            }

            if ($apuRequireDual) {
                $layout = strtolower((string) ($r->module_config ?? ''));
                // Accept 2x8 and 2x8GB spellings. Fail closed: an unverified
                // layout is never assumed to be dual channel.
                if (preg_match('/2\s*x\s*8\s*gb|2x8/', $layout) !== 1) {
                    return false;
                }
            }

            return true;
        });
    if (!$ram) {
        printf(
            "  %-44s SKIPPED: no %dGB %s%s%s (catalogue has none for this socket)\n",
            $def['name'],
            $ramBytes,
            $memGen,
            $apuRamMinSpeed > 0 ? " >= {$apuRamMinSpeed}" : '',
            $apuRequireDual ? ' in 2x8' : ''
        );
        continue;
    }
    $ramSpeed = specsOf($ram)['speed'] ?? null;
    $ramLayout = $ram->module_config ?? null;
    if ($apuRequireDual) {
        printf(
            "  %-44s RAM:  %s GBP %s, %s, %s (dual channel)\n",
            $def['name'], $ram->name, number_format((float) $ram->price, 2),
            (string) $ramSpeed, (string) $ramLayout
        );
    }

    // orderBy('price') is REQUIRED, not cosmetic.
//
// This query used to be ->get()->first(...) with no ordering at all, so
// "the first row with >=1TB" was whatever order Postgres happened to return -
// effectively arbitrary. It selected a GBP 520 drive in every tier and then
// reported the tier as too expensive, which is how all three bands came to
// fail. RAM right above already used orderBy('price')->first(...), so this
// also makes the two selections consistent.
$storage = DB::table('components')->where('category_id', $ids['Storage'])->where('active', 1)
    ->whereNotNull('price')->where('price', '>', 0)
    ->orderBy('price')->get()
    ->first(function ($s) {
        $cap = (int) preg_replace('/[^0-9]/', '', (string) (specsOf($s)['capacity'] ?? ''));
        return $cap >= 1000;
    });
    if (!$storage) {
        printf("  %-44s SKIPPED: no >=1TB storage\n", $def['name']);
        continue;
    }

    // CPU + GPU + ~90W for board/RAM/drives/fans. Expression was previously
    // buried in a precedence trap; now explicit.
    $estDraw = $cpuWatts + $gpuWatts + 90;
    $psu = DB::table('components')->where('category_id', $ids['PSU'])->where('active', 1)
        ->whereNotNull('price')->whereNotNull('wattage')
        ->where('wattage', '>=', (int) ceil($estDraw * 1.4)) // 40% headroom
        ->orderBy('wattage')->get()
        // Do not massively over-provision, but do not scale the ceiling with
        // draw alone. A 155W APU box drew a 341W cap (estDraw * 2.2), and the
        // catalogue records nothing between 217W and 341W, so the starter tier
        // was skipped for "no PSU" - while a 500W unit is the smallest sensible
        // ATX PSU and is what anyone would actually fit. 2.2x still binds on the
        // high-draw builds (410W draw -> 902W cap), so the guard keeps its teeth
        // where over-provisioning is the actual risk.
        ->first(function ($p) use ($estDraw) {
            $ceiling = max($estDraw * 2.2, 500);

            return ((int) $p->wattage) <= $ceiling;
        });
    if (!$psu) {
        printf("  %-44s SKIPPED: no PSU with >=%dW headroom\n", $def['name'], (int) ceil($estDraw * 1.4));
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
        printf("  %-44s SKIPPED: no ATX-capable case\n", $def['name']);
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
        printf("  %-44s SKIPPED: no recognised-brand cooler >= £%.0f\n", $def['name'], $coolerMin);

        continue;
    }

    // An APU build has no GPU part at all. The key is omitted entirely rather
    // than keyed null: the total loop below dereferences ->price, and the
    // storefront renders one row per part, so a null would either crash or
    // print an empty GPU row. Insertion order is the display order.
    $parts = $isApu
        ? [
            'CPU' => $cpu, 'Motherboard' => $board, 'RAM' => $ram,
            'Storage' => $storage, 'PSU' => $psu, 'Case' => $case, 'Cooler' => $cooler,
        ]
        : [
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
    //
    // The skip line carries the full breakdown, not just the total. A bare
    // "outside band £700-£900" gives no way to tell whether one part is
    // overshooting or all eight are, which is the difference between a
    // selection bug and a stale band. It must name what it picked.
    if ($total < $def['budget'][0] || $total > $def['budget'][1]) {
        printf(
            "  %-44s SKIPPED: total £%.0f outside band £%d-£%d\n"
            . "  %-44s   picked [%s]\n",
            $def['name'], $total, $def['budget'][0], $def['budget'][1],
            '', implode(', ', $line)
        );
        continue;
    }

    printf("  %-44s £%.0f  [%s]\n", $def['name'], $total, implode(', ', $line));
    $prebuilts[] = [
        'name' => $def['name'],
        'total' => round($total, 2),
        'socket' => $socket,
        // Honest labelling for the storefront. An APU build has to say plainly
        // that it has no dedicated graphics and what that means for the buyer,
        // otherwise "starter" implies an ordinary small PC and the customer
        // discovers the limit after buying. The panel renders both fields.
        'tagline' => $def['tagline'] ?? null,
        'notes' => $def['notes'] ?? null,
        'integrated_graphics' => $isApu,
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
            'gpu_chipset_source' => $isApu ? 'n/a - integrated graphics on CPU' : 'specs.chipset',
            'gpu_vram_gb' => $vram,
            'gpu_watts_source' => $isApu
                ? 'n/a - no discrete GPU; iGPU draws from the CPU TDP'
                : ($gpu->wattage ? 'components.wattage' : 'default 200W (no TDP recorded)'),
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