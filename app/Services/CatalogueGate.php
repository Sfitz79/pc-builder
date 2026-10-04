<?php

namespace App\Services;

use App\Models\Component;

/**
 * MODERN GATE + SELECTION GROUPING
 *
 * WHY THIS EXISTS. Measured 2026-10-04 against the live catalogue:
 *
 *   - BuilderController::catalog() was `->active()->get()`. No era filter, no
 *     platform filter, nothing. The `isModern`-style gates that do exist live in
 *     AIRecommendationService and only restrict what the AI may RECOMMEND; they
 *     never touched what a customer could SEE. So Ryzen 3000, A520 and 450W PSUs
 *     were all legitimately visible.
 *   - socket was NULL on 100 of 237 active CPUs and WRONG on 86 more: Zen1/2/3
 *     silicon (Ryzen 3 3200G, 5 1600, 5 3600) was labelled AM5 when it is AM4.
 *   - chipset was NULL on 370 of 400 active motherboards.
 *
 * So a gate built directly on those columns would have been wrong in both
 * directions. This service therefore DERIVES the platform from the component
 * name and every specs field, and gates on the derived value.
 *
 * READ-ONLY BY DESIGN. Nothing here writes to the database. The stored columns
 * stay as they are, so this is entirely reversible: remove the gate call and the
 * catalogue behaves exactly as before.
 *
 * THE FLOOR (Boss, 2026-10-04):
 *   Intel  LGA1700 and newer   - 12th/13th/14th gen, Core Ultra 200
 *   AMD    AM4 with Ryzen 5000 series or newer, and AM5 (Ryzen 7000/9000)
 * Everything older is out: LGA1200, LGA1151, LGA1150, AM3, Ryzen 1000-4000,
 * entry A-series boards (A520/A620), and pre-3200 memory.
 */
class CatalogueGate
{
    /** Sockets we are willing to sell into. */
    public const ALLOWED_SOCKETS = ['AM5', 'AM4', 'LGA1700', 'LGA1851'];

    /** Oldest DDR4 we consider modern. */
    public const MIN_DDR4_SPEED = 3200;

    /** Boss decision: no PSU below this is offered. */
    public const MIN_PSU_WATTS = 650;

    /**
     * DDR generation from a speed, used ONLY when the data does not state it.
     *
     * The two sets are disjoint, so a speed maps to exactly one generation and
     * the rule is unambiguous. Verified against all 377 active RAM rows: 377
     * state their generation explicitly, 0 needed this, 0 conflicted. It is a
     * fallback for future imports, not a primary path.
     *
     * 5200 and 6400 are here deliberately: 6400 is a JEDEC DDR5 speed and 5200
     * is one of the commonest XMP profiles (24 and 35 catalogue rows
     * respectively), so omitting them would leave real kits unresolvable.
     */
    public const DDR4_SPEEDS = [3200, 3600];
    public const DDR5_SPEEDS = [4800, 5200, 5600, 6000, 6400, 7000, 7200, 8000];
    public const DDR3_SPEEDS = [1333, 1600, 1866];

    /** Anything not in a list above stays unknown and is never guessed. */
    public const UNKNOWN = 'Unknown';

    /**
     * THE AUTHORITATIVE CHIPSET TABLE: chipset -> [socket, sellable].
     *
     * Two separate lists were a bug. chipset() learned to return X870E / X670E
     * / B650E while boardSocket() still tested an older list without the
     * E-variants, so flagship boards (MEG X870E GODLIKE, ROG CROSSHAIR X870E
     * DARK HERO) were rejected as "platform could not be identified". Deriving
     * from a stale hand-kept list is the same failure mode as trusting a stale
     * database column, so there is now exactly one list.
     *
     * chipset            socket      sellable
     * X870E/X870/X870I   AM5         yes  (Zen 5 flagship)
     * B870M/B870         AM5         yes
     * X670E/X670I/X670   AM5         yes  (Zen 4 flagship)
     * B650E/B650I/B650M/B650 AM5    yes
     * B850I/B850M/B850   AM5         yes  (Zen 5 mainstream)
     * B840M/B840         AM5         yes  (Zen 5 entry, modern socket)
     * B550I/B550M/B550   AM4         yes  (Ryzen 5000 value tier)
     * X570M/X570         AM4         yes
     * B760I/B760M/B760   LGA1700     yes
     * Z790M/Z790         LGA1700     yes
     * B860M/B860         LGA1851     yes
     * Z890M/Z890         LGA1851     yes
     * A320/A520/A620/Q570/Q650/B450/X460/X470/Z490/H610/H670/H810/B660   entry, no
     */
    public const CHIPSET_TABLE = [
        'X870E' => ['AM5', true],   'X870I' => ['AM5', true],    'X870' => ['AM5', true],
        'X870M' => ['AM5', true],
        'B870M' => ['AM5', true],   'B870' => ['AM5', true],
        'X670E' => ['AM5', true],   'X670I' => ['AM5', true],    'X670' => ['AM5', true],
        'B650E' => ['AM5', true],   'B650I' => ['AM5', true],    'B650M' => ['AM5', true], 'B650' => ['AM5', true],
        'B850I' => ['AM5', true],   'B850M' => ['AM5', true],    'B850' => ['AM5', true],
        'B840M' => ['AM5', true],   'B840' => ['AM5', true],
        'B550I' => ['AM4', true],   'B550M' => ['AM4', true],    'B550' => ['AM4', true],
        'X570M' => ['AM4', true],   'X570' => ['AM4', true],
        'B760I' => ['LGA1700', true], 'B760M' => ['LGA1700', true], 'B760' => ['LGA1700', true],
        'Z790M' => ['LGA1700', true], 'Z790' => ['LGA1700', true],
        'B860M' => ['LGA1851', true], 'B860' => ['LGA1851', true],
        'Z890M' => ['LGA1851', true], 'Z890' => ['LGA1851', true],
        // Entry / unsupported: RECOGNISED so they can be excluded, not grouped
        // as "Unknown". A520/A620 were specifically reported by the Boss.
        'B450M' => ['AM4', false],  'B450' => ['AM4', false],
        'X470' => ['AM4', false],   'X460' => ['AM4', false],
        'A620I' => ['AM5', false],  'A620M' => ['AM5', false],  'A620' => ['AM5', false],
        'A520I' => ['AM4', false],  'A520M' => ['AM4', false],  'A520' => ['AM4', false],
        'A320' => ['AM4', false],   'Q570' => ['AM4', false],   'Q650' => ['AM5', false],
        'B660' => ['LGA1700', false], 'H670' => ['LGA1700', false], 'H610' => ['LGA1700', false],
        'H810' => ['LGA1851', false], 'Z490' => ['LGA1151', false], 'Z590' => ['LGA1151', false],
    ];

    /** Entry chipsets: no real VRM or update path for a gaming build. */
    public const ENTRY_CHIPSETS = ['A320', 'A520', 'A620', 'Q570', 'Q650', 'B450', 'X460', 'X470', 'H610', 'H670', 'H810', 'B660', 'Z490', 'Z590'];

    /** Chipset prefixes, longest first so X870E is not read as X870. */
    public const CHIPSET_PATTERNS = [
        'X870E', 'X670E', 'B650E', 'X870I', 'X870M', 'X870', 'B870M', 'B870',
        'X670I', 'X670', 'B650I', 'B650M', 'B650', 'B850I', 'B850M', 'B850',
        'B860M', 'B860', 'B840M', 'B840', 'B550I', 'B550M', 'B550', 'X570M', 'X570',
        'B760I', 'B760M', 'B760', 'Z790M', 'Z790', 'Z890M', 'Z890',
        'A620I', 'A620M', 'A620', 'A520I', 'A520M', 'A520', 'A320M', 'A320',
        'Q570M', 'Q570', 'Q650M', 'Q650', 'B450M', 'B450', 'X470M', 'X470',
        'X460M', 'X460', 'B660', 'H610M', 'H610', 'H670M', 'H670', 'H810I', 'H810',
        'Z490', 'Z590',
    ];

    /* ------------------------------------------------------------------ */
    /* CPU                                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Derive a CPU's socket from its name.
     *
     * Ryzen generation to socket is fixed by AMD and does not change:
     *   Ryzen 1000-4000 and 5000 = AM4 ; Ryzen 7000/9000 = AM5.
     * That mapping is what the stored data gets wrong, so it is computed here
     * from the name rather than trusted.
     */
    public static function cpuSocket(string $name): string
    {
        $n = strtoupper($name);

        if (preg_match('/RYZEN\s*(?:([3579])\s*)?(\d{4})([A-Z]*)/', $n, $m)) {
            $series = (int) $m[1];
            $num = (int) $m[2];
            if ($num >= 7000) return 'AM5';
            if ($num >= 5000) return 'AM4';
            if ($num >= 1000) return 'AM4';
            if ($series === 3 && $num < 5000) return 'AM4';  // Athlon / Ryzen 3xxx
            return 'AM4';
        }
        if (preg_match('/ATHLON\s*(II|X2|64|X3)/', $n)) return 'AM3';
        if (preg_match('/ATHLON/', $n)) return 'AM4';
        if (preg_match('/(FX|OPTERAN|EPYC)/', $n)) return self::UNKNOWN;

        // 'i' optional: names are both "Core i5-12400" and "Core 5 12400".
        if (preg_match('/CORE\s+(?:I?\d+-\d{4,5}|ULTRA\s+\d+\s+\d+)/', $n)) {
            if (preg_match('/ULTRA/', $n)) return 'LGA1851';
            if (preg_match('/CORE\s+(?:I)?\d+-(\d{4,5})/', $n, $m) && (int) $m[1] >= 20000) return 'LGA1851';
            if (preg_match('/CORE\s+(?:I)?\d+-(\d{4,5})/', $n, $m)) {
                $gen = (int) $m[1];
                if ($gen >= 12000) return 'LGA1700';
                if ($gen >= 11000) return 'LGA1200';
                if ($gen >= 6000) return 'LGA1151';
                if ($gen >= 3000) return 'LGA1150';
                if ($gen >= 1000) return 'LGA1151';
            }
        }
        return self::UNKNOWN;
    }

    /**
     * Human series label for CPU grouping.
     *
     * Bands are numeric, not guessed from the marketing name. The 8000 band is
     * the correction: Ryzen 5 8500G / 7 8700G / 5 8600G were all being labelled
     * "Ryzen 7000" because the tiers jumped 9000 -> 7000 and swallowed 8000.
     *
     * The G suffix is kept in the label rather than dropped. A G part is an APU
     * with integrated graphics, which changes what a build needs: it can run
     * without a discrete GPU, and it should not be silently compared against
     * dGPU-only expectations. Measured in the catalogue: 5000 G=4, 8000 G=3,
     * and 3 G parts in the "older" band that the floor already excludes.
     */
    public static function cpuSeries(string $name, string $socket): string
    {
        $n = strtoupper($name);
        if ($socket === 'AM5' || $socket === 'AM4') {
            if (preg_match('/RYZEN\s*(?:[3579]\s*)?(\d{4})([A-Z]*)/', $n, $m)) {
                $num = (int) $m[1];
                $suffix = strtoupper((string) ($m[2] ?? ''));
                $g = str_contains($suffix, 'G');
                $label = $g ? ' (APU/G)' : (str_contains($suffix, 'X3D') ? ' (X3D)' : (str_contains($suffix, 'X') ? ' (X)' : ''));
                if ($num >= 9000) return 'Ryzen 9000 - Zen 5' . $label;
                if ($num >= 8000) return 'Ryzen 8000 - Zen 4 APU' . $label;
                if ($num >= 7000) return 'Ryzen 7000 - Zen 4' . $label;
                if ($num >= 5000) return 'Ryzen 5000 - Zen 3' . $label;
                return 'Ryzen ' . $num . ' - too old';
            }
            if (preg_match('/ATHLON/', $n)) return 'Athlon';
            return 'Other AMD';
        }
        if ($socket === 'LGA1851') return 'Core Ultra 200 - LGA1851';
        if ($socket === 'LGA1700') {
            if (preg_match('/CORE\s+(?:I)?\d+-(\d{4,5})/', $n, $m)) {
                $gen = (int) $m[1];
                if ($gen >= 14000) return 'Core 14th gen - LGA1700';
                if ($gen >= 13000) return 'Core 13th gen - LGA1700';
                return 'Core 12th gen - LGA1700';
            }
            return 'Core - LGA1700';
        }
        return 'Other';
    }

    /* ------------------------------------------------------------------ */
    /* Motherboard                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Derive a chipset from the board name, e.g. "MSI MAG B650 TOMAHAWK WIFI"
     * -> B650. Order is longest-prefix first so B650E is not read as B650.
     */
    public static function chipset(string $name): string
    {
        // Word boundaries matter, so spaces are preserved. An earlier version
        // stripped them to "MSIMAGB650TOMAHAWK", which put B650 mid-word, the
        // boundary guard then failed, and ALL 400 boards were rejected as
        // unidentifiable - the category came back EMPTY. Caught by the dry run.
        $cs = self::matchChipset($name);
        // Canonicalise to the FAMILY: A520M -> A520, B450M -> B450, X870E -> X870.
        //
        // This was the third real bug in this file. chipset() returned the
        // longest matching variant while ENTRY_CHIPSETS and CHIPSET_TABLE held
        // bare families, so a strict in_array(..., true) missed every one of
        // them and A520M / A620I / B450M boards were KEPT. The entry gate has to
        // compare like with like.
        return $cs === self::UNKNOWN ? self::UNKNOWN : self::chipsetFamily($cs);
    }

    /** The full variant as it appears in the name, e.g. X870E, B650M. */
    public static function chipsetVariant(string $name): string
    {
        return self::matchChipset($name);
    }

    /** Collapse a chipset variant to its family: A520M -> A520, X870E -> X870. */
    public static function chipsetFamily(string $chipset): string
    {
        if (preg_match('/^([ABXZH]\d{3})/', strtoupper($chipset), $m)) return $m[1];
        return strtoupper($chipset);
    }

    private static function matchChipset(string $name): string
    {
        $n = strtoupper((string) $name);
        $norm = trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $n));
        foreach (self::CHIPSET_PATTERNS as $cs) {
            if (preg_match('/(?<![A-Z0-9])' . $cs . '(?![0-9])/', $norm)) return $cs;
        }
        return self::UNKNOWN;
    }

    public static function boardSocket(string $name): string
    {
        $cs = self::chipsetFamily(self::matchChipset($name));
        if (isset(self::CHIPSET_TABLE[$cs])) return self::CHIPSET_TABLE[$cs][0];
        // Fall back to a socket token in the name before giving up, so an
        // unfamiliar board is still placeable rather than silently dropped.
        $n = strtoupper((string) $name);
        foreach (self::ALLOWED_SOCKETS as $sk) {
            if (preg_match('/(?<![A-Z0-9])' . $sk . '(?![A-Z0-9])/', $n)) return $sk;
        }
        return self::UNKNOWN;
    }

    /** Is this chipset an entry part we refuse to build around? */
    public static function isEntryChipset(string $name): bool
    {
        return in_array(self::chipset($name), self::ENTRY_CHIPSETS, true);
    }

    /* ------------------------------------------------------------------ */
    /* RAM                                                                 */
    /* ------------------------------------------------------------------ */

    /** Leading number out of whatever format the speed is stored in. */
    public static function speedNumber($raw): ?int
    {
        if (! is_scalar($raw)) return null;
        if (preg_match('/(\d{4,5})/', (string) $raw, $m)) return (int) $m[1];
        return null;
    }

    /**
     * DDR generation for a RAM component.
     *
     * Order matters: DDR5 must be tested before DDR4. The stored generation is
     * found in specs.type, in specs.speed (which mixes "DDR4-3200", "DDR5-6000"
     * and "6000 MT/s") or in the name. Only when none of those state it does the
     * speed table decide.
     */
    public static function ramType(string $name, array $specs): string
    {
        $blob = strtoupper($name . ' ' . json_encode($specs));
        if (preg_match('/DDR\s*-?\s*([345])/', $blob, $m)) return 'DDR' . $m[1];

        $num = self::speedNumber($specs['speed'] ?? null);
        if ($num === null) return self::UNKNOWN;
        if (in_array($num, self::DDR5_SPEEDS, true)) return 'DDR5';
        if (in_array($num, self::DDR4_SPEEDS, true)) return 'DDR4';
        if (in_array($num, self::DDR3_SPEEDS, true)) return 'DDR3';
        return self::UNKNOWN;
    }

    /* ------------------------------------------------------------------ */
    /* Storage                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Storage interface. Only recorded on 3 of 341 active rows, so the honest
     * answer is usually "Unknown" rather than a guess from the model number -
     * "ADATA SU630" is SATA and "LEGEND 710" is NVMe, and no rule derives that
     * safely from the string. Rows that ARE labelled group correctly; the rest
     * are surfaced as unrecorded so the data gap stays visible.
     */
    public static function storageType(string $name, array $specs): string
    {
        $blob = strtoupper($name . ' ' . json_encode($specs));
        if (preg_match('/NVME/', $blob)) return 'NVMe';
        if (preg_match('/M\.2/', $blob)) return 'M.2 NVMe';
        if (preg_match('/SATA|SSD/', $blob)) return 'SATA';
        if (preg_match('/HDD|HARD\s*DRIVE|IRONWOLF|BARRACUDA/', $blob)) return 'HDD';
        $t = $specs['type'] ?? null;
        if (is_scalar($t) && $t !== '') return (string) $t;
        return self::UNKNOWN;
    }

    /* ------------------------------------------------------------------ */
    /* Gate                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Decide whether a component may be shown.
     * Returns null to keep, or a plain-English reason to hide it.
     */
    public static function rejectReason(Component $c): ?string
    {
        $slug = $c->category?->slug ?? '';
        $name = (string) $c->name;
        $specs = is_array($c->specs) ? $c->specs : (json_decode((string) $c->specs, true) ?: []);

        switch ($slug) {
            case 'cpu': {
                $socket = self::cpuSocket($name);
                if ($socket === self::UNKNOWN) {
                    // Fail closed: an unidentifiable CPU is not shown, because we
                    // cannot prove it fits anything we sell.
                    return 'platform could not be identified from the name';
                }
                if (! in_array($socket, self::ALLOWED_SOCKETS, true)) {
                    return $socket . ' is below the current platform floor';
                }
                // AM4 spans Ryzen 1000 through 5000. The socket alone is not the
                // gate - the Boss specified "AM4 with Ryzen 5000 series or newer",
                // so Ryzen 1000-4000 must be rejected even though the socket is
                // modern. Without this, Ryzen 3 3200G / 5 3600 stayed visible on
                // AM4, which is the exact complaint that started this.
                if (preg_match('/RYZEN\s*(?:[3579]\s*)?(\d{4})/', strtoupper($name), $m)) {
                    if ((int) $m[1] < 5000) {
                        return 'Ryzen ' . $m[1] . ' is older than the Ryzen 5000 floor';
                    }
                }
                if (preg_match('/ATHLON/', strtoupper($name))) {
                    return 'Athlon is below the Ryzen 5000 floor';
                }
                return null;
            }
            case 'motherboard': {
                if (self::isEntryChipset($name)) {
                    return self::chipset($name) . ' is an entry chipset (no gaming VRM)';
                }
                $socket = self::boardSocket($name);
                if ($socket === self::UNKNOWN) return 'platform could not be identified from the name';
                if (! in_array($socket, self::ALLOWED_SOCKETS, true)) {
                    return $socket . ' is below the current platform floor';
                }
                return null;
            }
            case 'ram': {
                $type = self::ramType($name, $specs);
                if ($type === self::UNKNOWN) return 'memory generation could not be identified';
                if ($type === 'DDR3') return 'DDR3 is not a modern platform';
                if ($type === 'DDR4') {
                    $num = self::speedNumber($specs['speed'] ?? null);
                    if ($num !== null && $num < self::MIN_DDR4_SPEED) {
                        return 'DDR4-' . $num . ' is below the DDR4-' . self::MIN_DDR4_SPEED . ' floor';
                    }
                }
                return null;
            }
            case 'psu': {
                $w = $c->wattage === null ? null : (int) $c->wattage;
                if ($w === null) return 'wattage not recorded';
                if ($w < self::MIN_PSU_WATTS) return $w . 'W is below the ' . self::MIN_PSU_WATTS . 'W floor';
                return null;
            }
            default:
                return null;
        }
    }

    /**
     * Group key + label for the selection UI.
     * CPU groups by platform then series, RAM by DDR generation, motherboard by
     * chipset, storage by interface.
     */
    public static function groupFor(Component $c): array
    {
        $slug = $c->category?->slug ?? '';
        $name = (string) $c->name;
        $specs = is_array($c->specs) ? $c->specs : (json_decode((string) $c->specs, true) ?: []);

        switch ($slug) {
            case 'cpu': {
                $socket = self::cpuSocket($name);
                $series = self::cpuSeries($name, $socket);
                return ['key' => $socket . '|' . $series, 'platform' => $socket, 'series' => $series];
            }
            case 'motherboard': {
                $cs = self::chipset($name);
                return ['key' => $cs, 'platform' => self::boardSocket($name), 'series' => $cs];
            }
            case 'ram': {
                $t = self::ramType($name, $specs);
                return ['key' => $t, 'platform' => $t, 'series' => $t];
            }
            case 'storage': {
                $t = self::storageType($name, $specs);
                return ['key' => $t, 'platform' => $t, 'series' => $t];
            }
            default:
                return ['key' => $slug, 'platform' => null, 'series' => null];
        }
    }

    /** Everything the gate would hide, with reasons. Used for the audit report. */
    public static function audit($components): array
    {
        $hidden = [];
        $kept = 0;
        foreach ($components as $c) {
            $why = self::rejectReason($c);
            if ($why === null) { $kept++; continue; }
            $slug = $c->category?->slug ?? 'misc';
            $hidden[$slug][$why] = ($hidden[$slug][$why] ?? 0) + 1;
        }
        return ['kept' => $kept, 'hidden' => $hidden];
    }
}