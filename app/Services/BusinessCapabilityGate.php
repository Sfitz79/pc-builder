<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Component;
use Illuminate\Support\Facades\Cache;

/**
 * Can we actually build this workload from what we stock?
 *
 * THE PROBLEM THIS EXISTS TO FIX
 * ------------------------------
 * PCTG Business could ask for a NAS and receive an RTX 5070 in an RGB NZXT case.
 * The budget shares were working - the workload weights genuinely moved the
 * money - but there was nothing in the catalogue for the money to move
 * towards, so the quote was a cheaper gaming PC wearing a business badge.
 *
 * Measured against production 2026-10-15 (2,703 active components, read through
 * the product name AND the JSON spec keys):
 *
 *     server_cpu            Xeon / EPYC / Threadripper    0
 *     workstation_chipset   W680 / C266 / WRX90 / TRX50   0
 *     ecc_memory            ECC or RDIMM                 0
 *     enterprise_drive      IronWolf / WD Red / Exos     0
 *     pro_gpu               Radeon Pro / RTX A / Quadro   1
 *
 * Reading the name alone is not enough and has already been wrong in this
 * project: a probe using 'X870' as a workstation marker matched 123 consumer
 * X870 gaming boards and would have reported the catalogue as full of
 * workstation silicon. Every check below therefore looks at specs as well.
 *
 * WHAT IT DOES
 * ------------
 * For each business workload, resolves its declared `requires` against the live
 * catalogue and reports what is missing. A workload with an unmet requirement
 * is not offered as a one-click build - it is offered as a spec conversation.
 * Rule 6 in AGENTS.md, applied to the configurator: unknown must never equal
 * permitted.
 *
 * Read-only. It changes nothing about what we stock; it only stops us quoting
 * what we cannot assemble.
 */
class BusinessCapabilityGate
{
    /**
     * 5 minutes. The catalogue only moves when a price or stock refresh runs,
     * and the answer only decides which buttons render.
     */
    private const CACHE_KEY = 'business-capabilities:v1';

    private const TTL = 300;

    /**
     * How each capability is proven present, per category.
     *
     * `name` and `specs` are checked because the catalogue is inconsistent about
     * which one carries the signal: a scraped row may have an empty chipset
     * column and a usable product name, or the reverse.
     *
     * @return array<string, array<string, mixed>>
     */
    private const CHECKS = [
        'server_cpu' => [
            'category' => 'cpu',
            'name' => ['Xeon', 'EPYC', 'Threadripper', 'W-3', 'W7', 'W9'],
        ],
        'workstation_chipset' => [
            'category' => 'motherboard',
            'name' => ['W680', 'C266', 'WRX90', 'TRX50', 'Pro WS', 'ProArt', 'X670E', 'X870E', 'X870 '],
            'specs' => ['chipset'],
            'spec_values' => ['W680', 'C266', 'WRX90', 'TRX50', 'X670E', 'X870E'],
        ],
        'ecc_memory' => [
            'category' => 'ram',
            'name' => ['ECC', 'RDIMM', 'Registered', 'Fully-Buffered'],
            'specs' => ['type', 'speed', 'name'],
            'spec_values' => ['ecc', 'rdimm', 'registered', 'fully-buffered'],
        ],
        'enterprise_drive' => [
            'category' => 'storage',
            'name' => ['IronWolf', 'WD Red', 'Exos', 'SAS', 'NHDD', 'Purple', 'HSA', 'Seagate'],
            'specs' => ['interface', 'type', 'name'],
            'spec_values' => ['sas', 'sata enterprise'],
        ],
        'pro_gpu' => [
            'category' => 'gpu',
            'name' => ['Radeon Pro', 'RTX A', 'Quadro', 'Tesla', 'Arc Pro', 'W7900', 'W7800', 'W6800', 'WX '],
            'specs' => ['chipset', 'memory_type'],
            'spec_values' => ['pro', 'quadro', 'rtx a'],
        ],
        'quiet_cooling' => [
            'category' => 'cooler',
            // Air coolers only. A 360mm AIO is the single loudest thing in the
            // list, so a workload that says "quiet" must not be handed one - and
            // "Thermalright", "Noctua" and "Peerless" are all air.
            'name' => ['Noctua', 'Thermalright', 'Peerless', 'Phantom', 'Tower', 'Air', 'DeepCool AK', 'be quiet'],
        ],
    ];

    /**
     * Capabilities this class cannot decide from the catalogue alone.
     *
     * multi_drive_bay needs BOTH a case with bays AND a schema that can hold
     * more than one drive. Production has one storage category, so even a
     * eight-bay case could not be quoted with eight drives in it. Deciding it
     * here rather than in the Blade keeps the answer honest: "we could not
     * build it" covers a structural limit just as well as a missing part.
     */
    private function multiDriveBay(): bool
    {
        $storageCategories = Category::query()->where('slug', 'like', 'storage%')->count();

        if ($storageCategories < 2) {
            return false;
        }

        return $this->hasCaseWithBays();
    }

    private function hasCaseWithBays(): bool
    {
        $rows = Component::query()
            ->active()
            ->whereHas('category', fn ($q) => $q->where('slug', 'case'))
            ->get(['name', 'specs']);

        foreach ($rows as $row) {
            $specs = is_array($row->specs) ? $row->specs : [];

            foreach (['bays', 'drive_bays', 'drivebays', 'storage_bays'] as $key) {
                if (isset($specs[$key]) && (int) $specs[$key] >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is one capability present in the catalogue?
     */
    public function has(string $capability): bool
    {
        if ($capability === 'multi_drive_bay') {
            return $this->multiDriveBay();
        }

        $check = self::CHECKS[$capability] ?? null;

        if ($check === null) {
            // An UNKNOWN capability must not read as satisfied. Treating it as
            // present would put the button back on; treating it as absent means a
            // typo in config quietly removes a product line from sale, which is
            // visible and recoverable. Fail closed and say so.
            return false;
        }

        $query = Component::query()
            ->active()
            ->whereHas('category', fn ($q) => $q->where('slug', $check['category']));

        $rows = $query->get(['name', 'specs']);

        foreach ($rows as $row) {
            $name = (string) $row->name;

            foreach ($check['name'] ?? [] as $needle) {
                if (stripos($name, $needle) !== false) {
                    return true;
                }
            }

            $specs = is_array($row->specs) ? $row->specs : [];

            foreach ($check['specs'] ?? [] as $key) {
                $haystack = json_encode($specs[$key] ?? null);

                if (! is_string($haystack)) {
                    continue;
                }

                foreach ($check['spec_values'] ?? [] as $needle) {
                    if (stripos($haystack, $needle) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * What we stock, as capability => true/false.
     *
     * @return array<string, bool>
     */
    public function inventory(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            $out = [];

            foreach (array_keys(config('workloads.capabilities', [])) as $capability) {
                $out[$capability] = $this->has($capability);
            }

            return $out;
        });
    }

    /**
     * The verdict for one workload.
     *
     * @return array{
     *     buildable: bool,
     *     required: string[],
     *     missing: string[],
     *     missing_labels: string[]
     * }
     */
    public function verdict(string $slug): array
    {
        $capabilities = (array) config('workloads.capabilities', []);
        $workload = (array) config('workloads.workloads.'.$slug, []);

        // A capability key that is not declared in `capabilities` cannot be
        // checked, so it counts as MISSING rather than being dropped. Silently
        // discarding it would let a typo in a workload's `requires` quietly
        // remove a product line from sale - invisible, and wrong.
        $required = array_values(array_filter(
            (array) ($workload['requires'] ?? []),
            fn ($key) => is_string($key) && $key !== ''
        ));

        $unknown = array_values(array_filter(
            $required,
            fn (string $key) => ! array_key_exists($key, $capabilities)
        ));

        $inventory = $this->inventory();

        $missing = array_values(array_filter(
            $required,
            fn (string $key) => ! array_key_exists($key, $capabilities) || empty($inventory[$key])
        ));

        return [
            'buildable' => $missing === [],
            'required' => $required,
            'missing' => $missing,
            'unknown' => $unknown,
            'missing_labels' => array_map(
                fn (string $key) => (string) ($capabilities[$key]['label'] ?? $key),
                $missing
            ),
        ];
    }

    /**
     * Split the business workloads into what we can build and what we cannot.
     *
     * @return array{buildable: array<string, array>, spec_only: array<string, array>}
     */
    public function partition(): array
    {
        $buildable = [];
        $specOnly = [];

        foreach (GeminiService::businessWorkloads() as $slug => $workload) {
            $verdict = $this->verdict($slug);

            $buildable[$slug] = $verdict['buildable']
                ? $workload + ['verdict' => $verdict]
                : null;

            $specOnly[$slug] = $verdict['buildable']
                ? null
                : $workload + ['verdict' => $verdict];
        }

        return [
            'buildable' => array_filter($buildable),
            'spec_only' => array_filter($specOnly),
        ];
    }

    /**
     * Forget the cached inventory. Call after any catalogue ingest.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
