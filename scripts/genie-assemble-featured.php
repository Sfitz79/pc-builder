<?php
/*
 * Assemble every cell of the featured-build taxonomy into real, policy-compliant
 * systems, and publish them to database/scraped/featured-builds.json.
 *
 * Reads config/featured_builds.php for the taxonomy and config/build_policy.php
 * (via App\Services\BuildPolicyGate) for every floor. Nothing is hand-priced:
 * a cell is only published if real catalogue parts, read from production, add
 * up inside that cell's advertised band.
 *
 * THE POINT OF GENERATING RATHER THAN WRITING BY HAND: a featured system must
 * never be able to contain a part the storefront would refuse to sell. Both
 * come from the same policy gate, so that cannot drift.
 *
 * Selection is "best value inside the band", not "cheapest":
 *   - CPU: 8 cores or more first, then most expensive inside the band, so a
 *     cell spends its budget on cores before anything else (Boss: "always
 *     prioritise 8 core cpu").
 *   - GPU: highest performance tier the band affords, then priciest at that
 *     tier (Boss: "best gpu for money").
 *   - Everything else: cheapest compliant part, with M.2 preferred for storage.
 *
 * Run: php scripts/genie-assemble-featured.php [--apply]
 * Without --apply it reports what it would do and writes nothing.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use App\Services\BuildPolicyGate;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv ?? [], true);
$policy = new BuildPolicyGate();
$taxonomy = (array) config('featured_builds');
$gpuFloors = (array) ($taxonomy['gpu_tier_floor'] ?? []);
$budgets = (array) ($taxonomy['budget_gbp'] ?? []);
$gpuService = app(App\Services\AIRecommendationService::class);

$cats = DB::table('categories')->pluck('id', 'name');
foreach (['CPU', 'Motherboard', 'GPU', 'RAM', 'Storage', 'PSU', 'Case', 'Cooler'] as $n) {
    if (! isset($cats[$n])) {
        fwrite(STDERR, "missing category {$n}\n");
        exit(1);
    }
}
$ids = [];
foreach (['CPU', 'Motherboard', 'GPU', 'RAM', 'Storage', 'PSU', 'Case', 'Cooler'] as $n) {
    $ids[$n] = $cats[$n];
}

$active = fn (string $cat) => DB::table('components')
    ->where('category_id', $ids[$cat])->where('active', 1)
    ->whereNotNull('price')->where('price', '>', 0)->get();

$specsOf = function (object $r): array {
    $s = $r->specs ?? null;

    return is_array($s) ? $s : (json_decode((string) $s, true) ?: []);
};

$stock = [];
foreach (['CPU', 'Motherboard', 'GPU', 'RAM', 'Storage', 'PSU', 'Case', 'Cooler'] as $cat) {
    $stock[$cat] = $active($cat);
}
echo 'pool sizes: ';
foreach ($stock as $cat => $rows) {
    echo $cat . '=' . $rows->count() . ' ';
}
echo "\n\n";

/**
 * Deterministic pseudo-random pick, seeded from the cell so a cell always
 * produces the same product. Real randomness would rename the storefront on
 * every page load.
 */
function seededPick(array $pool, string $seed): mixed
{
    if ($pool === []) {
        return null;
    }
    $h = crc32($seed);
    $pool = array_values($pool);

    return $pool[$h % count($pool)];
}

/**
 * Every cell of the taxonomy, flattened to one list.
 *
 * The nine GAMING systems are hand-named in config because they are the flagship
 * range and their names are a brand asset. The eighteen professional cells
 * (3 usages x 3 resolutions x mid/high) are SYNTHESISED from the name pools,
 * deterministically from usage/resolution/tier.
 *
 * Deterministic, not random: the Boss asked for these to be "randomly created",
 * but a storefront that renames itself on every page load cannot be tested,
 * cannot be advertised and cannot be linked to. Same cell, same product, every
 * time.
 */
function cells(array $taxonomy): array
{
    $out = [];

    // Hand-named gaming range, plus any other hand-authored entry.
    foreach ($taxonomy['systems'] as $resKey => $tiers) {
        foreach ($tiers as $tier => $def) {
            if (($def['usage'] ?? '') !== 'gaming') {
                continue;
            }
            $resolution = $def['resolution']
                ?? preg_replace('/^(gaming|streaming|content_creation|studio)_/', '', $resKey);
            $out[] = ['resolution' => $resolution, 'tier' => $tier] + $def;
        }
    }

    // Synthesise the professional usages across every resolution.
    $pools = $taxonomy['generated_name_pools'] ?? [];
    $resLabels = ['1080P' => '1080p', '1440P' => '1440p', '4K' => '4K'];
    foreach ($taxonomy['coverage'] as $usage => $tiers) {
        if ($usage === 'gaming') {
            continue;
        }
        $pool = $pools[$usage] ?? ['names' => ['System'], 'summaries' => ['Built for the work.']];
        // Positional index, not a hash. crc32 per cell collided and produced
        // three separate products all called "PCTG Transmission" - which is
        // unusable on a storefront even though the slugs stayed unique.
        $slot = 0;
        foreach ($resLabels as $resolution => $resLabel) {
            foreach ($tiers as $tier) {
                $word = $pool['names'][$slot % max(count($pool['names']), 1)];
                $sIdx = $slot % max(count($pool['summaries']), 1);
                $slot++;
                // Coalesce before interpolating: "??" is not valid inside a
                // double-quoted string's {} expression.
                $usageLabel = $taxonomy['axes']['usage'][$usage] ?? $usage;
                $out[] = [
                    'usage' => $usage,
                    'resolution' => $resolution,
                    'tier' => $tier,
                    'slug' => $word . '-' . $resLabel . '-' . $tier,
                    'name' => 'PCTG ' . $word,
                    'summary' => $pool['summaries'][$sIdx],
                    'promise' => "Built for {$usageLabel} at {$resLabel}, {$tier} performance.",
                    'platform' => 'AM5',
                ];
            }
        }
    }

    return $out;
}

$results = [];
$skipped = [];

foreach (cells($taxonomy) as $cell) {
    $usage = $cell['usage'];
    $resolution = $cell['resolution'];
    $tier = $cell['tier'];
    $label = strtoupper($resolution) . ' ' . $tier . ' / ' . $usage;

    $band = $budgets[$usage][$resolution][$tier] ?? null;
    if ($band === null) {
        $skipped[$label] = 'no budget band defined';

        continue;
    }
    [$bandMin, $bandMax] = $band;
    $socket = $cell['platform'] ?? 'AM5';
    $isApu = ! empty($cell['apu']);
    $gpuFloor = (int) ($gpuFloors[$resolution][$tier] ?? 2);

    // ---- BUDGET ALLOCATION ------------------------------------------------
    //
    // Order: cheapest compliant supporting parts, then the GPU (hard tier
    // floor), then the CPU (8-core preference), then the PSU.
    //
    // Three earlier allocations were wrong and each showed up in the skip list:
    //   1. every part filtered against the whole band, total checked at the end -
    //      eight individually-affordable parts are not an affordable machine.
    //      Identical GBP 3554.41 totals across twelve cells.
    //   2. supporting parts taken AFTER the CPU and GPU, which starved them
    //      ("no compliant PSU inside the remaining budget" on thirteen cells).
    //   3. supporting parts first but with the BOARD priced descending, so the
    //      board ate the budget - then reordering without fixing the board.
    //
    // Supporting parts are the cheapest compliant option, and they are small:
    // board ~GBP 70-100, memory ~GBP 100-200, drive ~GBP 50-95, case ~GBP 30-50,
    // cooler ~GBP 17-40. Taking them first is what leaves a real GPU budget.
    // The PSU is left until last because its wattage band depends on the CPU and
    // GPU it has to power.
    $remaining = (float) $bandMax;

    $take = function (?object $row) use (&$remaining) {
        if ($row === null) {
            return null;
        }
        $remaining -= (float) $row->price;

        return $row;
    };

    $boardRow = $take($stock['Motherboard']
        ->filter(function ($b) use ($policy, $socket) {
            if (! $policy->boardAllowed($b)['ok']) {
                return false;
            }
            $s = strtoupper(trim((string) ($b->socket ?? '')));
            if ($s === '') {
                $s = strtoupper(trim(App\Services\CatalogueGate::boardSocket((string) $b->name)));
            }

            return $s === $socket;
        })
        ->sortBy('price')
        ->first(fn ($b) => (float) $b->price <= $remaining));
    $ramRow = $take($stock['RAM']->filter(fn ($r) => $policy->ramAllowed($r, $socket)['ok'])
        ->sortBy('price')->first(fn ($r) => (float) $r->price <= $remaining));
    $storageRow = $take($stock['Storage']->filter(fn ($s) => $policy->storageAllowed($s)['ok'])
        ->sortBy(fn ($s) => [$policy->storageRank($s), (float) $s->price])
        ->first(fn ($s) => (float) $s->price <= $remaining));
    $caseRow = $take($stock['Case']
        ->filter(fn ($c) => $policy->brandAllowed($c->name)['ok'])
        ->filter(function ($c) use ($specsOf) {
            $s = $specsOf($c);

            return ($s['form_factor'] ?? '') === 'ATX';
        })
        ->sortBy('price')
        ->first(fn ($c) => (float) $c->price <= $remaining));
    $coolerRow = $take($stock['Cooler']
        ->filter(fn ($c) => $policy->brandAllowed($c->name)['ok'])
        ->filter(fn ($c) => (float) $c->price >= 10)
        ->sortBy('price')
        ->first(fn ($c) => (float) $c->price <= $remaining));

    if (! $boardRow || ! $ramRow || ! $storageRow || ! $caseRow || ! $coolerRow) {
        $skipped[$label] = 'supporting parts do not fit the band';

        continue;
    }

    // Reserve for the PSU so the GPU and CPU cannot leave nothing to power them.
    $psuReserve = 110.0;
    $spendable = $remaining - $psuReserve;

    $cpus = $stock['CPU']->filter(function ($c) use ($policy, $socket, $isApu) {
        if (! $policy->cpuAllowed($c)['ok']) {
            return false;
        }
        $s = strtoupper(trim((string) ($c->socket ?? '')));
        if ($s === '') {
            $s = strtoupper(trim(App\Services\CatalogueGate::cpuSocket((string) $c->name)));
        }
        if ($s !== $socket) {
            return false;
        }
        // An APU cell must carry a G-series part: without one there is no
        // display output at all.
        if ($isApu && preg_match('/\b\d{4}GT?\b/i', (string) $c->name) !== 1) {
            return false;
        }

        return true;
    });

    // ---- GPU + CPU: the trade-off ----------------------------------------
    //
    // Both matter and they compete for the same money: the brief asks for an
    // 8-core CPU AND the best GPU for the money, so neither may simply be served
    // first. Four allocations got this wrong and every one showed up as a wall
    // of skips:
    //   - GPU first with no CPU reserve ate the CPU budget entirely
    //     ("no compliant CPU inside the remaining budget" on twelve cells).
    //   - CPU first with no cap ate the PSU budget ("no compliant PSU" on
    //     fourteen cells).
    //   - A hard-coded percentage share still failed, because how much is left
    //     depends on which supporting parts the policy happened to admit.
    //
    // So this resolves the trade-off by walking the GPU cap DOWN until both a
    // compliant 8-core-or-better CPU and a compliant PSU fit. That is
    // self-correcting: it cannot be defeated by an unexpected supporting-part
    // cost, which is what every fixed split was.
    $pickGpu = function (float $cap) use ($stock, $policy, $gpuFloor, $gpuService, $specsOf) {
        $best = null;
        $bestTier = -1;
        $bestPrice = -1.0;
        foreach ($stock['GPU'] as $g) {
            if ((float) $g->price > $cap) {
                continue;
            }
            if (! $policy->gpuAllowed($g)['ok']) {
                continue;
            }
            $model = new App\Models\Component();
            $model->forceFill([
                'name' => $g->name, 'chipset' => $g->chipset, 'specs' => $specsOf($g),
                'wattage' => $g->wattage, 'price' => $g->price, 'active' => true,
            ]);
            $gt = $gpuService->gpuPerformanceTierPublic($model);
            if ($gt < $gpuFloor) {
                continue;
            }
            // Highest tier wins; priciest WITHIN that tier. Comparing only the
            // tier meant the first card at the top tier won, so every cell landed
            // on the same card.
            if ($gt > $bestTier || ($gt === $bestTier && (float) $g->price > $bestPrice)) {
                $bestTier = $gt;
                $bestPrice = (float) $g->price;
                $best = $g;
            }
        }

        return [$best, $bestTier];
    };

    $pickCpu = function (float $cap) use ($cpus, $policy) {
        $pool = $cpus->filter(fn ($c) => (float) $c->price <= $cap);

        return $pool->filter(fn ($c) => $policy->prefersEightCores($c))->sortByDesc('price')->first()
            ?? $pool->sortByDesc('price')->first();
    };

    $pickPsu = function (int $draw) use ($stock, $policy) {
        $band = $policy->psuWattageBand($draw);

        return $stock['PSU']
            ->filter(fn ($p) => $policy->brandAllowed($p->name)['ok'])
            ->filter(fn ($p) => (int) $p->wattage >= $band['min'] && (int) $p->wattage <= $band['max'])
            ->sortBy(fn ($p) => [$policy->psuEfficiencyRank($p)['rank'], (float) $p->price])
            ->first();
    };

    $gpu = null;
    $cpu = null;
    $psu = null;
    $gpuTierUsed = 0;

    if ($isApu) {
        // No discrete card: the CPU is the only compute part that matters, so
        // it takes the whole remaining budget.
        $cpu = $pickCpu($remaining);
    } else {
        // 12 steps from "spendable" down to 25% of it.
        for ($step = 12; $step >= 1; $step--) {
            $cap = $spendable * ($step / 12);
            [$gCand, $gTier] = $pickGpu($cap);
            if (! $gCand) {
                continue;
            }
            $left = $remaining - (float) $gCand->price;
            $cCand = $pickCpu($left);
            if (! $cCand) {
                continue;
            }
            $left2 = $left - (float) $cCand->price;
            $pCand = $pickPsu((int) ($cCand->wattage ?: 65) + (int) ($gCand->wattage ?: 200) + 90);
            if (! $pCand || (float) $pCand->price > $left2) {
                continue;
            }
            $gpu = $gCand;
            $gpuTierUsed = $gTier;
            $cpu = $cCand;
            $psu = $pCand;
            break;
        }
    }

    if (! $cpu) {
        $skipped[$label] = 'no compliant 8-core-or-better CPU fits alongside the GPU';

        continue;
    }
    $cpu = $take($cpu);
    if (! $isApu) {
        $gpu = $take($gpu);
        $psu = $take($psu);
    }

    $cpuSpecs = $specsOf($cpu);
    $cores = (int) ($cpuSpecs['cores'] ?? 0);
    $cpuWatts = (int) ($cpu->wattage ?: 65);

    if ($isApu) {
        // APU builds still need a PSU, sized on the CPU alone.
        $psu = $take($pickPsu($cpuWatts + 90));
        if (! $psu) {
            $skipped[$label] = 'no compliant PSU inside the remaining budget';

            continue;
        }
    }

    $gpuWatts = $isApu ? 0 : (int) ($gpu->wattage ?: 200);

    $parts = $isApu
        ? ['CPU' => $cpu, 'Motherboard' => $boardRow, 'RAM' => $ramRow, 'Storage' => $storageRow,
            'PSU' => $psu, 'Case' => $caseRow, 'Cooler' => $coolerRow]
        : ['CPU' => $cpu, 'GPU' => $gpu, 'Motherboard' => $boardRow, 'RAM' => $ramRow,
            'Storage' => $storageRow, 'PSU' => $psu, 'Case' => $caseRow, 'Cooler' => $coolerRow];

    $total = 0.0;
    foreach ($parts as $p) {
        $total += (float) $p->price;
    }

    if ($total < $bandMin || $total > $bandMax) {
        $skipped[$label] = sprintf('GBP %.2f outside band %d-%d', $total, $bandMin, $bandMax);

        continue;
    }

    $psuEff = $policy->psuEfficiencyRank($psu);

    $results[] = [
        'slug' => $cell['slug'],
        'name' => $cell['name'],
        'summary' => $cell['summary'],
        'promise' => $cell['promise'] ?? null,
        'tags' => [
            'usage' => $usage,
            'usage_label' => $taxonomy['axes']['usage'][$usage] ?? $usage,
            'resolution' => strtoupper($resolution),
            'tier' => $tier,
            'tier_label' => ($taxonomy['axes']['tier'][$tier] ?? $tier) . ' performance',
        ],
        'total' => round($total, 2),
        'budget_band' => $band,
        'socket' => $socket,
        'integrated_graphics' => $isApu,
        'psu_efficiency' => $psuEff['rating'],
        'psu_efficiency_source' => $psuEff['known']
            ? 'NAME MATCH on the product name - no efficiency field exists in the catalogue'
            : 'NOT STATED by the supplier; no efficiency field exists in the catalogue',
        'parts' => array_map(fn ($t, $p) => [
            'type' => $t,
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
        ], array_keys($parts), array_values($parts)),
    ];

    printf(
        "  %-34s GBP %-9s %s / %-4s / %-3s  %d parts\n",
        $cell['name'], number_format($total, 2), $usage, strtoupper($resolution), $tier, count($parts)
    );
}

printf("\npublished %d of %d cells\n", count($results), count(cells($taxonomy)));
if ($skipped) {
    echo "skipped:\n";
    foreach ($skipped as $label => $why) {
        printf("  %-34s %s\n", $label, $why);
    }
}

if (! $apply) {
    echo "\nDRY RUN. Nothing written. Re-run with --apply.\n";
    exit(0);
}

$out = __DIR__ . '/../database/scraped/featured-builds.json';
file_put_contents($out, json_encode([
    'generated' => date('c'),
    'source' => 'production pgsql, genie-prod-guard asserted',
    'policy' => 'config/build_policy.php via App\Services\BuildPolicyGate',
    'count' => count($results),
    'builds' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
printf("\nwritten: %s (%d builds)\n", basename($out), count($results));