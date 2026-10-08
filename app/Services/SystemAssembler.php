<?php

namespace App\Services;

/**
 * Assembles one policy-compliant system for a specification.
 *
 * EXISTS TO PREVENT DRIFT. The allocation logic was first written inside
 * scripts/genie-assemble-featured.php, where it was iterated through five
 * faults before it was correct. If the AI builder grew its own copy, the next
 * change would have to be made twice and the two would diverge - which is
 * exactly how the case form factor, the missing rule row, the dropped label and
 * the re-admitted chipset all happened in this project.
 *
 * So the CLI script and the AI builder both call this.
 *
 * A SPEC looks like:
 *   platform      AM4 | AM5 | LGA1700 | LGA1851
 *   gpu_tier_floor int 0-5, 0 means "no discrete card required" (APU)
 *   budget_min    GBP floor the total must reach
 *   budget_max    GBP ceiling the total must not exceed
 *   apu           bool, when true the CPU must be a G-series part
 *
 * Every hard floor comes from BuildPolicyGate, never from this class, so a
 * featured system and an AI quote are gated identically.
 */
class SystemAssembler
{
    public function __construct(
        protected BuildPolicyGate $policy,
        protected AIRecommendationService $gpuService,
    ) {
    }

    /**
     * @return array{ok: bool, reason: ?string, parts: array<string,object>, total: float, cores: int, gpu_tier: int}
     */
    public function assemble(array $spec): array
    {
        $platform = strtoupper((string) ($spec['platform'] ?? 'AM5'));
        $gpuFloor = (int) ($spec['gpu_tier_floor'] ?? 2);
        $bandMin = (float) ($spec['budget_min'] ?? 0);
        $bandMax = (float) ($spec['budget_max'] ?? 0);
        $isApu = ! empty($spec['apu']);

        $fail = fn (string $why) => [
            'ok' => false, 'reason' => $why, 'parts' => [], 'total' => 0.0,
            'cores' => 0, 'gpu_tier' => 0,
        ];

        if ($bandMax <= 0) {
            return $fail('no budget ceiling was supplied');
        }

        $cats = \Illuminate\Support\Facades\DB::table('categories')->pluck('id', 'name');
        $ids = [];
        foreach (['CPU', 'Motherboard', 'GPU', 'RAM', 'Storage', 'PSU', 'Case', 'Cooler'] as $n) {
            if (! isset($cats[$n])) {
                return $fail("missing category {$n}");
            }
            $ids[$n] = $cats[$n];
        }

        $pool = [];
        foreach (array_keys($ids) as $cat) {
            $pool[$cat] = \Illuminate\Support\Facades\DB::table('components')
                ->where('category_id', $ids[$cat])->where('active', true)
                ->whereNotNull('price')->where('price', '>', 0)->get();
        }

        $specsOf = function (object $r): array {
            $s = $r->specs ?? null;

            return is_array($s) ? $s : (json_decode((string) $s, true) ?: []);
        };

        $socketOf = function (object $r, string $kind): string {
            $col = strtoupper(trim((string) ($r->socket ?? '')));
            if ($col !== '') {
                return $col;
            }

            return strtoupper(trim($kind === 'board'
                ? CatalogueGate::boardSocket((string) $r->name)
                : CatalogueGate::cpuSocket((string) $r->name)));
        };

        $remaining = $bandMax;
        $take = function (?object $row) use (&$remaining) {
            if ($row === null) {
                return null;
            }
            $remaining -= (float) $row->price;

            return $row;
        };

        // ---- Supporting parts: cheapest compliant, taken first -------------
        $board = $take($pool['Motherboard']
            ->filter(fn ($b) => $this->policy->boardAllowed($b)['ok'] && $socketOf($b, 'board') === $platform)
            ->sortBy('price')->first(fn ($b) => (float) $b->price <= $remaining));
        $ram = $take($pool['RAM']
            ->filter(fn ($r) => $this->policy->ramAllowed($r, $platform)['ok'])
            ->sortBy('price')->first(fn ($r) => (float) $r->price <= $remaining));
        $storage = $take($pool['Storage']
            ->filter(fn ($s) => $this->policy->storageAllowed($s)['ok'])
            ->sortBy(fn ($s) => [$this->policy->storageRank($s), (float) $s->price])
            ->first(fn ($s) => (float) $s->price <= $remaining));
        $case = $take($pool['Case']
            ->filter(fn ($c) => $this->policy->brandAllowed($c->name)['ok']
                && (($specsOf($c)['form_factor'] ?? '') === 'ATX'))
            ->sortBy('price')->first(fn ($c) => (float) $c->price <= $remaining));
        $cooler = $take($pool['Cooler']
            ->filter(fn ($c) => $this->policy->brandAllowed($c->name)['ok'] && (float) $c->price >= 10)
            ->sortBy('price')->first(fn ($c) => (float) $c->price <= $remaining));

        if (! $board || ! $ram || ! $storage || ! $case || ! $cooler) {
            return $fail('supporting parts do not fit the budget');
        }

        $cpus = $pool['CPU']->filter(function ($c) use ($platform, $isApu) {
            if (! $this->policy->cpuAllowed($c)['ok']) {
                return false;
            }
            if ($socketOf($c, 'cpu') !== $platform) {
                return false;
            }
            // An APU must be G-series or there is no display output at all.
            return ! $isApu || preg_match('/\b\d{4}GT?\b/i', (string) $c->name) === 1;
        });

        $pickGpu = function (float $cap) use ($pool, $gpuFloor, $specsOf) {
            $best = null;
            $bestTier = -1;
            $bestPrice = -1.0;
            foreach ($pool['GPU'] as $g) {
                if ((float) $g->price > $cap || ! $this->policy->gpuAllowed($g)['ok']) {
                    continue;
                }
                $model = new \App\Models\Component();
                $model->forceFill([
                    'name' => $g->name, 'chipset' => $g->chipset, 'specs' => $specsOf($g),
                    'wattage' => $g->wattage, 'price' => $g->price, 'active' => true,
                ]);
                $tier = $this->gpuService->gpuPerformanceTierPublic($model);
                if ($tier < $gpuFloor) {
                    continue;
                }
                // Highest tier wins, priciest WITHIN that tier. Comparing only the
                // tier made the first card encountered at the top tier win.
                if ($tier > $bestTier || ($tier === $bestTier && (float) $g->price > $bestPrice)) {
                    $bestTier = $tier;
                    $bestPrice = (float) $g->price;
                    $best = $g;
                }
            }

            return [$best, $bestTier];
        };

        $pickCpu = function (float $cap) use ($cpus) {
            $inRange = $cpus->filter(fn ($c) => (float) $c->price <= $cap);

            return $inRange->filter(fn ($c) => $this->policy->prefersEightCores($c))
                ->sortByDesc('price')->first()
                ?? $inRange->sortByDesc('price')->first();
        };

        $pickPsu = function (int $draw) use ($pool) {
            $band = $this->policy->psuWattageBand($draw);

            return $pool['PSU']
                ->filter(fn ($p) => $this->policy->brandAllowed($p->name)['ok'])
                ->filter(fn ($p) => (int) $p->wattage >= $band['min'] && (int) $p->wattage <= $band['max'])
                ->sortBy(fn ($p) => [$this->policy->psuEfficiencyRank($p)['rank'], (float) $p->price])
                ->first();
        };

        // ---- GPU + CPU trade-off ------------------------------------------
        // Both matter and compete for the same money, so the GPU cap is walked
        // DOWN until a compliant 8-core-or-better CPU and a compliant PSU both
        // fit. Four fixed allocations failed before this: GPU-first ate the CPU
        // budget, CPU-first ate the PSU's, a percentage share could not adapt to
        // whatever supporting parts the policy admitted, and an uncapped sort
        // simply took the most expensive affordable part.
        $psuReserve = 110.0;
        $spendable = $remaining - $psuReserve;

        $gpu = null;
        $cpu = null;
        $psu = null;
        $gpuTier = 0;

        if ($isApu) {
            $cpu = $take($pickCpu($remaining));
            if (! $cpu) {
                return $fail('no compliant APU fits the budget');
            }
            $psu = $take($pickPsu((int) ($cpu->wattage ?: 65) + 90));
            if (! $psu) {
                return $fail('no compliant PSU fits the budget');
            }
        } else {
            for ($step = 12; $step >= 1; $step--) {
                [$gCand, $gTier] = $pickGpu($spendable * ($step / 12));
                if (! $gCand) {
                    continue;
                }
                $left = $remaining - (float) $gCand->price;
                $cCand = $pickCpu($left);
                if (! $cCand) {
                    continue;
                }
                $pCand = $pickPsu((int) ($cCand->wattage ?: 65) + (int) ($gCand->wattage ?: 200) + 90);
                if (! $pCand || (float) $pCand->price > $left - (float) $cCand->price) {
                    continue;
                }
                $gpu = $take($gCand);
                $gpuTier = $gTier;
                $cpu = $take($cCand);
                $psu = $take($pCand);
                break;
            }
        }

        if (! $cpu || (! $isApu && ! $gpu)) {
            return $fail('no compliant CPU and GPU pair fits the budget');
        }

        $parts = $isApu
            ? ['CPU' => $cpu, 'Motherboard' => $board, 'RAM' => $ram, 'Storage' => $storage,
                'PSU' => $psu, 'Case' => $case, 'Cooler' => $cooler]
            : ['CPU' => $cpu, 'GPU' => $gpu, 'Motherboard' => $board, 'RAM' => $ram,
                'Storage' => $storage, 'PSU' => $psu, 'Case' => $case, 'Cooler' => $cooler];

        $total = 0.0;
        foreach ($parts as $p) {
            $total += (float) $p->price;
        }

        if ($total < $bandMin || $total > $bandMax) {
            return $fail(sprintf('total GBP %.2f outside band %.2f-%.2f', $total, $bandMin, $bandMax));
        }

        $cpuSpecs = $specsOf($cpu);

        return [
            'ok' => true,
            'reason' => null,
            'parts' => $parts,
            'total' => round($total, 2),
            'cores' => (int) ($cpuSpecs['cores'] ?? 0),
            'gpu_tier' => $gpuTier,
        ];
    }
}