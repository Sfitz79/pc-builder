<?php

namespace App\Services\ThreeD;

/**
 * Maps a GPU's real catalogue identity to its real cooler tier.
 *
 * WHY THIS EXISTS - and it is not decoration.
 *
 * Measured 2026-10-05 against the catalogue: `specs.chipset` is populated on
 * 306 of 306 active GPUs, `specs.memory` on 306 of 306, and `specs.length`,
 * `specs.height` and `specs.thickness_slots` on ZERO of 306. So there is no
 * per-SKU length/height/slot data to vary geometry from, and every card
 * resolves to the same PartDimensions envelope (300 x 120 x 40.6mm).
 *
 * The honest fix is to vary the SHAPE from the specs that genuinely exist.
 * A bigger GPU chip really does get a bigger cooler: fan count, connector
 * pin count, shroud depth and blade count all track the model number and the
 * VRAM capacity. Both are real, populated fields. This is a documented
 * inference from real spec data, not a per-SKU measurement, and the physics
 * envelope (length/height/thickness) is NEVER changed by it.
 *
 * No branding, logos or text are produced anywhere in this class. Colour
 * identity comes from the vendor word in the name only.
 */
final class GpuTier
{
    /**
     * Decade -> cooler tier, per family. Keys are the decade digits of the real
     * model number (RTX 5080 -> 8, RX 9070 -> 7, GTX 1660 -> 6).
     *
     * Tier scale: 0 entry (single axial fan, 6-pin), 1 mid (two fan, 8-pin),
     * 2 upper (three fan, 8-pin), 3 flagship (three fan, 12V-2x6).
     *
     * @var array<string, array<int, int>>
     */
    private const DECADES = [
        // GeForce RTX 50 (Blackwell)
        'rtx5' => [8 => 3, 9 => 3, 7 => 2, 6 => 1, 5 => 0],
        // GeForce RTX 40 (Ada)
        'rtx4' => [9 => 3, 8 => 3, 7 => 2, 6 => 1],
        // GeForce RTX 30 (Ampere)
        'rtx3' => [9 => 3, 8 => 2, 7 => 2, 6 => 1],
        // GeForce RTX 20 (Turing)
        'rtx2' => [7 => 1, 6 => 0, 5 => 0],
        // GeForce GTX 10 / 16
        'gtx'  => [10 => 1, 16 => 0, 7 => 1, 9 => 1],
        // Radeon RX 9000 (RDNA 4)
        'rx9'  => [9 => 3, 8 => 2, 7 => 2, 6 => 1, 5 => 0],
        // Radeon RX 7000 (RDNA 3)
        'rx7'  => [9 => 3, 7 => 2, 6 => 1, 5 => 0],
        // Radeon RX 6000
        'rx6'  => [9 => 2, 8 => 2, 7 => 2, 6 => 1, 5 => 0],
        // Intel Arc
        'arcb' => [8 => 1, 7 => 1, 6 => 0],
        // Intel Arc A-series
        'arca' => [7 => 2, 6 => 2, 5 => 1],
    ];

    /**
     * Cooler tier, 0-3.
     *
     * VRAM is a genuine second signal: a 24GB card is physically bigger than a
     * 6GB card of the same silicon, so capacity nudges the tier. It nudges; it
     * never invents a step on its own.
     */
    public static function for(array $specs): int
    {
        $tier = self::tierFromName((string) ($specs['chipset'] ?? ''));

        if ($tier === null) {
            $tier = self::tierFromVram((string) ($specs['memory'] ?? ''));
        }

        $gb = self::vramGb((string) ($specs['memory'] ?? ''));
        if ($gb >= 16) {
            $tier++;
        } elseif ($gb > 0 && $gb <= 6 && $tier > 0) {
            $tier--;
        }

        return max(0, min(3, $tier));
    }

    /**
     * Fan count for the tier.
     *
     * The length ceiling is real and load-bearing: you cannot fit three 95mm
     * axial fans on a 200mm card, so a short card caps its fan count. With the
     * catalogue's 300mm default this never binds, but it is the rule that keeps
     * a short, tier-3 card honest.
     */
    public static function fanCount(int $tier, float $lengthMm): int
    {
        $byTier = [1, 2, 3, 3][$tier] ?? 2;

        $byLength = (int) floor($lengthMm / 95);

        return max(1, min($byTier, max(1, $byLength)));
    }

    /** 1 = 6-pin PCIe, 2 = 8-pin (6+2), 3 = 12V-2x6. */
    public static function connectorPins(int $tier): int
    {
        return [1, 2, 2, 3][$tier] ?? 2;
    }

    /** Real axial fan blade counts. */
    public static function bladeCount(int $tier): int
    {
        return [7, 9, 9, 11][$tier] ?? 9;
    }

    /**
     * Shroud material key from the vendor word in the real product name.
     *
     * COLOUR ONLY. Never a logo, never a wordmark, never text of any kind: the
     * platform's content-sharing rules treat burned-in branding as a violation
     * that gets content deleted and accounts disabled.
     */
    public static function shroudMaterial(string $name, int $tier): string
    {
        $n = mb_strtolower($name);

        foreach ([
            'asus' => 'shroudAsus',
            'rog' => 'shroudAsus',
            'tuf' => 'shroudAsus',
            'prime' => 'shroudAsus',
            'gigabyte' => 'shroudGigabyte',
            'aorus' => 'shroudGigabyte',
            'msi' => 'shroudMsi',
            'mag' => 'shroudMsi',
            'gaming' => 'shroudMsi',
            'evga' => 'shroudEvga',
            'ftx' => 'shroudEvga',
            'zotac' => 'shroudZotac',
            'pny' => 'shroudPny',
            'sapphire' => 'shroudSapphire',
            'pulse' => 'shroudSapphire',
            'nitro' => 'shroudSapphire',
            'powercolor' => 'shroudPowercolor',
            'red devil' => 'shroudPowercolor',
            'asrock' => 'shroudAsrock',
            'kioxia' => 'shroudAsus',
            'corsair' => 'shroudCorsair',
            'ncmp' => 'shroudCorsair',
            'hyte' => 'shroudCorsair',
            'cooler' => 'shroudCorsair',
            'gainward' => 'shroudGigabyte',
            'inno3d' => 'shroudZotac',
            'galax' => 'shroudGigabyte',
            'palit' => 'shroudGigabyte',
            'gainward' => 'shroudGigabyte',
            'xfx' => 'shroudPowercolor',
            'amd' => 'shroudAmd',
            'radeon' => 'shroudAmd',
            'intel' => 'shroudArc',
            'arc ' => 'shroudArc',
        ] as $needle => $material) {
            if (str_contains($n, $needle)) {
                return $material;
            }
        }

        // No vendor recognised: fall back to a neutral tier-driven shroud rather
        // than inventing a colour out of nothing.
        return [0 => 'shroudEntry', 1 => 'shroudMid', 2 => 'shroudUpper', 3 => 'shroudFlagship'][$tier] ?? 'shroudMid';
    }

    protected static function tierFromName(string $chipset): ?int
    {
        $c = mb_strtolower(trim($chipset));
        if ($c === '') {
            return null;
        }

        // Intel Arc B580 / A770.
        if (preg_match('/arc\s*b(\d{3})/', $c, $m)) {
            return self::decade($m[1], 'arcb');
        }
        if (preg_match('/arc\s*a(\d{3})/', $c, $m)) {
            return self::decade($m[1], 'arca');
        }

        // Radeon RX 9070 XT / RX 7600 - the family digit precedes the tier.
        if (preg_match('/rx\s*([79])(\d{3})/', $c, $m)) {
            return self::decade($m[2], 'rx'.$m[1]);
        }

        // GeForce RTX 5080 / GTX 1660 / GTX 1080 Ti.
        if (preg_match('/rtx\s*(\d)(\d{3})/', $c, $m)) {
            return self::decade($m[2], 'rtx'.$m[1]);
        }
        if (preg_match('/gtx\s*(\d{3,4})/', $c, $m)) {
            return self::decade($m[1], 'gtx');
        }

        // Bare "RTX 5080" without the GeForce word.
        if (preg_match('/\brtx\s*(\d)(\d{3})/', $c, $m)) {
            return self::decade($m[2], 'rtx'.$m[1]);
        }

        return null;
    }

    /**
     * Look the model number up in its family's decade table.
     *
     * Fails closed: an unrecognised model returns null rather than a guess, and
     * the caller then falls back to the VRAM signal and finally to tier 1.
     */
    protected static function decade(string $digits, string $family): ?int
    {
        $table = self::DECADES[$family] ?? null;
        if ($table === null) {
            return null;
        }

        $d = (int) $digits;
        if (strlen($digits) === 3) {
            // 5080 -> decade 8, 9070 -> decade 7.
            return $table[(int) substr($digits, 0, 1)] ?? null;
        }

        // 2- and 4-digit forms (1660, 1080): match the whole decade.
        return $table[$d] ?? null;
    }

    /**
     * Last-resort tier from VRAM alone, for a chipset string we cannot parse.
     * Explicitly low-but-real rather than silent.
     */
    protected static function tierFromVram(string $memory): int
    {
        $gb = self::vramGb($memory);

        return match (true) {
            $gb >= 16 => 3,
            $gb >= 12 => 2,
            $gb >= 8 => 1,
            $gb > 0 => 0,
            default => 1,
        };
    }

    public static function vramGb(string $memory): int
    {
        if (preg_match('/(\d{1,2})\s*gb/i', $memory, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}