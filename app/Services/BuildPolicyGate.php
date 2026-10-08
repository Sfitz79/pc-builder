<?php

namespace App\Services;

/**
 * Evaluates config/build_policy.php against catalogue rows.
 *
 * Every rule returns a PASS or a FAIL WITH A REASON. The reason matters: a gate
 * that silently drops a part leaves nobody able to tell a policy decision from a
 * data defect, which is exactly how the missing form_factor_match rule row went
 * unnoticed for so long.
 *
 * Two conventions, both deliberate:
 *
 *   - HARD gates fail closed. If the data a rule needs is missing, the part is
 *     rejected rather than admitted. Rule 6: unknown must never equal permitted.
 *     Every hard gate here has been measured as fully populated, so failing
 *     closed cannot empty a pool.
 *
 *   - PREFERENCES never fail. They rank candidates. PSU efficiency is the case
 *     that forces this: there is no rating field for PSUs at all, so a hard gate
 *     on it would reject 261 of 287 and take the builder offline.
 */
class BuildPolicyGate
{
    /** @var array<string,mixed> */
    protected array $policy;

    public function __construct(?array $policy = null)
    {
        $this->policy = $policy ?? (array) config('build_policy', []);
    }

    public function policy(): array
    {
        return $this->policy;
    }

    /**
     * @return array{ok: bool, reason: ?string}
     */
    protected function verdict(bool $ok, ?string $reason = null): array
    {
        return ['ok' => $ok, 'reason' => $ok ? null : $reason];
    }

    // ---------------------------------------------------------------- brands

    /**
     * Brand policy. Blocked substrings always lose. Conditional blocks lose
     * unless the name matches one of their carve-outs.
     *
     * Gigabyte is conditional rather than blocked so the AORUS / Elite carve-out
     * sits next to the rule it modifies instead of hiding in a separate list.
     */
    public function brandAllowed(string $name, ?string $manufacturer = null): array
    {
        $haystack = strtolower(trim(($manufacturer ? $manufacturer . ' ' : '') . $name));

        foreach ($this->policy['brands']['blocked_substrings'] ?? [] as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return $this->verdict(false, "brand blocked: {$needle}");
            }
        }

        foreach ($this->policy['brands']['conditional_block'] ?? [] as $brand => $carveOuts) {
            if (! str_contains($haystack, strtolower($brand))) {
                continue;
            }
            foreach ($carveOuts as $rx) {
                if (preg_match($rx, $name) === 1) {
                    return $this->verdict(true);
                }
            }

            return $this->verdict(false, "brand blocked: {$brand} outside its allowed lines");
        }

        return $this->verdict(true);
    }

    // ------------------------------------------------------------------- cpu

    /**
     * CPU floor: AM4 model number >= 5500, Intel restricted to LGA1700 and
     * LGA1851 (12th gen and newer).
     *
     * The AM4 floor compares the four-digit MODEL, never the series word, and
     * never uses a trailing \b: "5600X" and "5500GT" have no word boundary after
     * the digits, so /\b5600\b/ rejects both.
     */
    public function cpuAllowed(object $cpu): array
    {
        $name = (string) $cpu->name;

        $brand = $this->brandAllowed($name, $cpu->manufacturer_name ?? null);
        if (! $brand['ok']) {
            return $brand;
        }

        // DERIVE the socket, do not trust the column. Measured on active priced
        // CPUs: 65 rows have a NULL socket column, and the scraper also stored
        // wrong values (Ryzen 3 3200G recorded as AM5 when it is AM4).
        // CatalogueGate::cpuSocket() already derives it from the model name and
        // is what catalogItem() publishes, so the gate reads the same value the
        // rest of the system sees.
        $socket = strtoupper(trim((string) ($cpu->socket ?? '')));
        if ($socket === '') {
            $socket = strtoupper(trim(CatalogueGate::cpuSocket($name)));
        }

        if (str_starts_with($socket, 'AM')) {
            $min = (int) ($this->policy['cpu']['am4_min_model'] ?? 5500);
            // Exactly four digits, not followed by another digit.
            //
            // The earlier /(\d{4})[A-Z]{0,3}\b/ could not match X3D parts:
            // [A-Z] does not match the "3" in "X3D", so the trailing \b never
            // held and 16 strong chips (9950X3D, 7900X3D, 7800X3D, 7600X3D,
            // 7500X3D, 9850X3D) were rejected as unparseable.
            if (! preg_match('/\b(\d{4})(?!\d)/', $name, $m)) {
                return $this->verdict(false, 'CPU model number could not be read');
            }
            $model = (int) $m[1];
            if ($model < $min) {
                return $this->verdict(false, "CPU model {$model} is below the {$socket} floor of {$min}");
            }

            return $this->verdict(true);
        }

        if (str_starts_with($socket, 'LGA')) {
            $allowed = $this->policy['cpu']['intel_allowed_sockets'] ?? ['LGA1700', 'LGA1851'];
            if (! in_array($socket, $allowed, true)) {
                return $this->verdict(false, "socket {$socket} is below the 12th-generation floor");
            }

            return $this->verdict(true);
        }

        return $this->verdict(false, "socket '{$socket}' could not be resolved to an approved platform");
    }

    /**
     * Preference, never a gate. 8 cores or more where the pool allows it.
     * Measured 8c+ availability: AM4 19/42, AM5 21/35, LGA1700 39/49, LGA1851
     * 14/14 - so this can be a preference on every platform without emptying
     * any pool.
     */
    public function cpuCoreCount(object $cpu): int
    {
        $specs = is_array($cpu->specs ?? null)
            ? $cpu->specs
            : (json_decode((string) ($cpu->specs ?? ''), true) ?: []);

        return (int) ($specs['cores'] ?? 0);
    }

    public function prefersEightCores(object $cpu): bool
    {
        return $this->cpuCoreCount($cpu) >= (int) ($this->policy['cpu']['prefer_min_cores'] ?? 8);
    }

    // ---------------------------------------------------------------- memory

    /**
     * Memory floor: 16GB total, dual channel 2x8, and a per-generation speed
     * floor derived from the socket.
     *
     * Layout is read from the module_config COLUMN. RAM specs carry only speed
     * and capacity - there is no specs.modules key, so a specs-only check
     * matches nothing at all and every kit looks like a single stick.
     *
     * Speed parsing strips the DDR4/DDR5 token FIRST. specs.speed is
     * "DDR4-3200"; stripping every non-digit yields 43200 and makes the floor
     * vacuous.
     */
    public function ramAllowed(object $ram, ?string $socket = null): array
    {
        $specs = is_array($ram->specs ?? null)
            ? $ram->specs
            : (json_decode((string) ($ram->specs ?? ''), true) ?: []);

        $totalGb = (int) preg_replace('/[^0-9]/', '', (string) ($specs['capacity'] ?? ''));
        $minGb = (int) ($this->policy['memory']['min_total_gb'] ?? 16);
        if ($totalGb < $minGb) {
            return $this->verdict(false, "memory {$totalGb}GB is below the {$minGb}GB minimum");
        }

        $speed = (string) ($specs['speed'] ?? '');
        if ($speed === '') {
            return $this->verdict(false, 'memory generation could not be read from specs.speed');
        }

        if (! empty($this->policy['memory']['require_dual_channel'])) {
            // Module COUNT, not a layout string. An exact "2 x 8" match rejected
            // 159 good 2x16 32GB kits; the rule is "no single sticks ever" plus a
            // 16GB minimum, so any kit of two or more modules qualifies.
            // Measured: 60 single-stick kits, 315 dual-channel.
            $layout = strtolower(trim((string) ($ram->module_config ?? '')));
            if (! preg_match('/^\s*(\d+)\s*x/i', $layout, $m)) {
                return $this->verdict(false, "memory layout '{$layout}' could not be read");
            }
            $modules = (int) $m[1];
            $minModules = (int) ($this->policy['memory']['min_module_count'] ?? 2);
            if ($modules < $minModules) {
                return $this->verdict(false, "memory is a single {$modules}-stick kit; dual channel is required");
            }
        }

        // Generation must match the platform, and the speed must clear that
        // generation's floor.
        $genMap = $this->policy['memory']['socket_generation'] ?? [];
        if ($socket !== null && isset($genMap[$socket])) {
            $expectGen = $genMap[$socket];
            if (stripos($speed, $expectGen) === false) {
                return $this->verdict(false, "memory '{$speed}' is not {$expectGen} as required by {$socket}");
            }
        }

        $floor = $this->policy['memory']['min_speed'] ?? [];
        foreach ($floor as $gen => $minSpeed) {
            if (stripos($speed, $gen) === false) {
                continue;
            }
            $numeric = preg_replace('/DDR[45]/i', '', $speed);
            $mt = (int) preg_replace('/[^0-9]/', '', (string) $numeric);
            if ($mt < (int) $minSpeed) {
                return $this->verdict(false, "memory speed {$mt} is below the {$gen} floor of {$minSpeed}");
            }

            return $this->verdict(true);
        }

        return $this->verdict(false, "memory '{$speed}' matches no known generation");
    }

    // --------------------------------------------------------------- storage

    /**
     * Storage floor: at least 500GB, SSD only, M.2 preferred.
     *
     * Field selection is measured, not assumed. On active priced storage:
     *   storage_type column  = "SSD" on 338 of 338
     *   specs.interface/type = populated on 0 of 338
     *   interface column     = "M.2 PCIe 4.0 X4", "SATA 6.0 Gb/s", "M.2 SATA"
     *
     * So SSD-ness is read from storage_type and the M.2/SATA distinction from
     * the interface column. The first version of this method read
     * specs.interface and rejected 338 of 338 rows - a gate that empties its
     * own pool, which is the failure mode worth watching for in every rule here.
     */
    public function storageAllowed(object $storage): array
    {
        $specs = is_array($storage->specs ?? null)
            ? $storage->specs
            : (json_decode((string) ($storage->specs ?? ''), true) ?: []);

        $cap = (int) preg_replace('/[^0-9]/', '', (string) ($specs['capacity'] ?? ''));
        $min = (int) ($this->policy['storage']['min_capacity_gb'] ?? 500);
        if ($cap < $min) {
            return $this->verdict(false, "storage {$cap}GB is below the {$min}GB minimum");
        }

        $type = strtoupper(trim((string) ($storage->storage_type ?? '')));
        $ssdTypes = $this->policy['storage']['ssd_types'] ?? ['SSD'];
        $isSsd = false;
        foreach ($ssdTypes as $t) {
            if (stripos($type, $t) !== false) {
                $isSsd = true;
                break;
            }
        }
        if (! $isSsd) {
            return $this->verdict(false, "storage type '{$type}' is not an SSD");
        }

        $interface = strtoupper(trim((string) ($storage->interface ?? '')));
        if ($interface === '') {
            return $this->verdict(false, 'storage interface could not be read');
        }

        $allowed = $this->policy['storage']['allowed_interface_prefixes'] ?? [];
        $ok = false;
        foreach ($allowed as $prefix) {
            if (stripos($interface, $prefix) !== false) {
                $ok = true;
                break;
            }
        }
        if (! $ok) {
            return $this->verdict(false, "storage interface '{$interface}' is not an accepted SSD interface");
        }

        return $this->verdict(true);
    }

    /**
     * Preference ranking for storage: M.2 / NVMe first.
     */
    public function storageRank(object $storage): int
    {
        $interface = strtoupper(trim((string) ($storage->interface ?? '')));
        foreach ($this->policy['storage']['prefer_interface'] ?? [] as $i => $wanted) {
            if (stripos($interface, $wanted) !== false) {
                return $i;
            }
        }

        return 99;
    }

    // ------------------------------------------------------------------- gpu

    /**
     * Graphics floor for any build with a discrete card: at least 8GB VRAM, and
     * a model at or above Intel B570 / RTX 3050 8GB / RX 7000 series.
     *
     * VRAM is the check that separates an RTX 3050 8GB from an RTX 3050 6GB -
     * the names are identical, so a name-only rule cannot tell them apart.
     *
     * Measured: specs.memory populated on 306 of 306 active priced GPUs, 290 at
     * or above 8GB, so failing closed on a missing value cannot empty the pool.
     *
     * Separate from AIRecommendationService::gpuPerformanceTier(): the tier says
     * how fast a card is, this says whether it is acceptable at all.
     */
    public function gpuAllowed(object $gpu): array
    {
        $specs = is_array($gpu->specs ?? null)
            ? $gpu->specs
            : (json_decode((string) ($gpu->specs ?? ''), true) ?: []);

        $rawMem = $specs['memory'] ?? null;
        if ($rawMem === null || $rawMem === '') {
            return $this->verdict(false, 'GPU VRAM could not be read from specs.memory');
        }

        $n = (float) preg_replace('/[^0-9.]/', '', (string) $rawMem);
        $gb = $n > 64 ? $n / 1024 : $n; // tolerate a row recording megabytes
        $minVram = (float) ($this->policy['gpu']['min_vram_gb'] ?? 8);
        if ($gb < $minVram) {
            return $this->verdict(false, sprintf('GPU has %.1fGB VRAM, below the %.0fGB floor', $gb, $minVram));
        }

        $haystack = strtoupper(trim(
            (string) ($gpu->chipset ?? '')
            . ' ' . (string) ($specs['chipset'] ?? '')
            . ' ' . (string) ($specs['gpu_chipset'] ?? '')
            . ' ' . (string) $gpu->name
        ));

        // A FLOOR, so cards are rejected by matching a below-floor family
        // rather than by failing to match an allow list. An allow list rejected
        // 271 of 306 cards including every RTX 50-series part, which are all far
        // above any floor.
        foreach ($this->policy['gpu']['reject_below_floor'] ?? [] as $rx) {
            if (preg_match($rx, $haystack) === 1) {
                return $this->verdict(false, 'GPU is below the B570 / RTX 3050 8GB / RX 7000 floor');
            }
        }

        return $this->verdict(true);
    }

    // ------------------------------------------------------------------- psu

    /**
     * PSU efficiency is a RANKING, never a gate.
     *
     * Measured: there is no efficiency field for PSUs anywhere. specs carry only
     * form_factor, width, height, depth and dimension_source; no column holds a
     * rating; only 26 of 287 active priced PSU names mention a rating at all.
     *
     * So the rating is parsed from the name where present, and a PSU with no
     * rating is ranked below Bronze rather than excluded. A hard gate here would
     * reject 261 of 287 and take the builder offline, and failing closed would
     * be dishonest about data we do not have.
     *
     * Until a real rating field is backfilled, nothing customer-facing may
     * claim an efficiency rating it cannot evidence.
     */
    public function psuEfficiencyRank(object $psu): array
    {
        $name = strtoupper((string) $psu->name);

        foreach ($this->policy['psu']['prefer'] ?? [] as $i => $tier) {
            if (preg_match('/\b' . preg_quote($tier, '/') . '\b/', $name) === 1) {
                return ['rank' => $i, 'rating' => $tier, 'known' => true];
            }
        }

        foreach ($this->policy['psu']['fallback'] ?? [] as $j => $tier) {
            if (preg_match('/\b' . preg_quote($tier, '/') . '\b/', $name) === 1) {
                return ['rank' => 10 + $j, 'rating' => $tier, 'known' => true];
            }
        }

        // 80 PLUS branding without a tier word still proves certification.
        if (preg_match('/80\s*\+?\s*PLUS/i', $name) === 1) {
            return ['rank' => 20, 'rating' => '80 PLUS (tier unstated)', 'known' => true];
        }

        return ['rank' => 99, 'rating' => null, 'known' => false];
    }

    /**
     * Headroom band for a PSU: a lower bound and a ceiling.
     *
     * The ceiling is max(draw * multiplier, absolute floor) rather than
     * draw-scaled alone. Scaling purely by draw capped a 155W APU box at 341W
     * while the catalogue records nothing between 217W and 341W, so the esports
     * tier was skipped for "no PSU" even though 500W is the smallest sensible
     * ATX unit.
     */
    public function psuWattageBand(int $estimatedDraw): array
    {
        $min = (int) ceil($estimatedDraw * (float) ($this->policy['psu']['min_headroom_multiplier'] ?? 1.4));
        $ceiling = (int) max(
            $estimatedDraw * (float) ($this->policy['psu']['over_provision_ceiling'] ?? 2.2),
            (float) ($this->policy['psu']['absolute_max_watts'] ?? 1000)
        );

        return ['min' => $min, 'max' => $ceiling];
    }

    // ---------------------------------------------------------------- boards

    /**
     * The AMD board gate, kept here so a per-tier chipset list cannot re-admit a
     * forbidden family.
     *
     * Family regexes with the optional suffix INSIDE the pattern. A numeric
     * suffix test is wrong and was measured to be: it keeps B450 (ends "50")
     * while dropping B550M, B650M, B850M and every "-E"/"-I" board.
     *
     * @see config/build_policy.php for the full rationale and the rejected
     *      X-prefix-only reading, which left 2 qualifying AM4 boards at GBP 369.
     */
    public const AMD_BOARD_FAMILIES = [
        '/\bB550M?\b/i',
        '/\bX570\b/i',
        '/\bB650[EM]?\b/i',
        '/\bX670[E]?\b/i',
        '/\bB850M?\b/i',
        '/\bX870[EMI]?\b/i',
    ];

    public function boardAllowed(object $board): array
    {
        $name = (string) $board->name;
        $brand = $this->brandAllowed($name, $board->manufacturer_name ?? null);
        if (! $brand['ok']) {
            return $brand;
        }

        $socket = strtoupper(trim((string) $board->socket));
        if (! in_array($socket, ['AM4', 'AM5'], true)) {
            // Non-AMD sockets are not governed by the AMD family list.
            return $this->verdict(true);
        }

        foreach (self::AMD_BOARD_FAMILIES as $rx) {
            if (preg_match($rx, $name) === 1) {
                return $this->verdict(true);
            }
        }

        return $this->verdict(false, "board chipset is outside the approved AMD families for {$socket}");
    }

    // ------------------------------------------------------------ whole build

    /**
     * Apply every hard gate to a candidate selection at once.
     *
     * Used by the assembler and the AI builder so a build that would be rejected
     * is never presented to the customer in the first place, rather than being
     * shown and then refused at checkout.
     *
     * @param  array<string,object|null>  $parts  keyed by category slug
     * @param  array<string,string>       $sockets category slug => socket
     * @return array{ok: bool, failures: array<string,string>}
     */
    public function buildAllowed(array $parts, array $sockets = []): array
    {
        $failures = [];

        $checks = [
            'cpu' => fn ($p) => $this->cpuAllowed($p),
            'motherboard' => fn ($p) => $this->boardAllowed($p),
            'ram' => fn ($p) => $this->ramAllowed($p, $sockets['ram'] ?? null),
            'storage' => fn ($p) => $this->storageAllowed($p),
            'gpu' => fn ($p) => $this->gpuAllowed($p),
        ];

        foreach ($checks as $slug => $check) {
            $part = $parts[$slug] ?? null;
            if ($part === null) {
                continue; // absent categories are handled by the caller
            }
            $res = $check($part);
            if (! $res['ok']) {
                $failures[$slug] = $res['reason'];
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
    }
}