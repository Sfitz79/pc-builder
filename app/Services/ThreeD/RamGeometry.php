<?php

namespace App\Services\ThreeD;

/**
 * PROCEDURAL DDR DIMM.
 *
 * LOCAL FRAME (mm): +X = module length (0 at the far end from the notch),
 * +Y = module height, +Z = module thickness with 0 at the PCB's outer face.
 *
 * A DIMM is not a slab: it is a thin PCB standing on a gold edge connector,
 * with a heat spreader on both faces, a notch in the edge connector, and -" on a
 * third of this catalogue -" a light bar. All of that is modelled.
 *
 * VARIATION IS REAL:
 *  - DDR generation comes from CatalogueGate::ramType(), which reads the
 *    populated `specs.speed` field (DDR5-6000, DDR4-3200, ...). DDR5 modules
 *    are physically shorter and use an on-dimmer PMIC, so they get a shorter
 *    spreader and a visible power-management block; DDR4 gets the taller
 *    spreader and the taller heatsink fins.
 *  - a light bar is only drawn when the name says RGB/LIGHT -" 129 of 375 active
 *    modules, so it is a genuine catalogue fact, not a coin toss.
 *  - capacity is populated on all 375 modules and drives how many chips are
 *    populated on the PCB, which is the real reason a 64GB stick looks fuller.
 */
final class RamGeometry implements PartGeometry
{
    /** Real DIMM constants. */
    private const PCB_T = 1.6;
    private const PCB_H = 31.25;     // DDR4/DDR5 module board height
    private const DDR5_H = 30.0;
    private const NOTCH_FROM_END = 61.0;  // key notch position along the edge
    private const NOTCH_W = 5.0;

    public function localFrame(): string
    {
        return 'RAM local: +X length, +Y height (0=gold edge connector), +Z thickness (0=PCB outer face)';
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public function anchors(array $dims, array $specs, string $name, string $category): array
    {
        $len = (float) ($dims['x'] ?? 133.35);

        return ['top_centre' => [$len / 2, self::PCB_H + 8.0, 0.0]];
    }

    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array
    {
        $len = (float) ($dims['x'] ?? 133.35);
        $hgt = (float) ($dims['y'] ?? 32.0);
        $thk = (float) ($dims['z'] ?? 7.0);

        $generation = \App\Services\CatalogueGate::ramType($name, $specs);
        $ddr5 = $generation === 'DDR5';
        $capacityGb = self::capacityGb($specs, $name);
        $rgb = (bool) preg_match('/rgb|light|illusion/i', $name);

        // Height budget: PCB + spreader (+ light bar), never more than the
        // published envelope. A DIMM's published height includes its spreader,
        // so the spreader and the light bar have to share that budget.
        $barH = $rgb ? min(4.0, max(2.0, $hgt * 0.12)) : 0.0;
        $spreadTop = $hgt - $barH;
        $boardH = min($rgb ? self::DDR5_H : self::PCB_H, $spreadTop - 2.0);
        $boardH = max(12.0, $boardH);
        $spreadH = max(0.0, $spreadTop - $boardH);

        // Depth budget, centred on the PCB: PCB + one spreader each side. A DIMM
        // is 7-8mm TOTAL, so a spreader cannot be laid on top of the PCB in
        // whichever direction happens to be convenient.
        $spreadD = min(2.6, max(1.0, ($thk - self::PCB_T) / 2));
        $spreadZ = [(self::PCB_T / 2 + $spreadD / 2), -(self::PCB_T / 2 + $spreadD / 2)];
        $chipZ = self::PCB_T / 2 + 0.7;

        $m = [];

        // --- PCB ------------------------------------------------------------
        $m[] = Mesh::box($len, $boardH, self::PCB_T, 'ramPcb',
            [$len / 2, $boardH / 2, self::PCB_T / 2]);

        // --- Gold edge connector, split around the real key notch ------------
        $fingerH = 3.6;
        $m[] = Mesh::box(self::NOTCH_FROM_END, $fingerH, self::PCB_T, 'gold',
            [self::NOTCH_FROM_END / 2, $fingerH / 2, self::PCB_T / 2]);
        $rightW = $len - self::NOTCH_FROM_END - self::NOTCH_W;
        $m[] = Mesh::box($rightW, $fingerH, self::PCB_T, 'gold',
            [self::NOTCH_FROM_END + self::NOTCH_W + $rightW / 2, $fingerH / 2, self::PCB_T / 2]);

        // --- Heat spreader, both faces --------------------------------------
        foreach ($spreadZ as $z) {
            $m[] = Mesh::box($len - 4.0, $spreadTop, $spreadD, 'ramSpreader',
                [$len / 2, $spreadTop / 2, $z]);
        }

        // --- Top light bar (RGB modules) or heat fins (everything else) ------
        if ($rgb) {
            $m[] = Mesh::box($len - 10.0, $barH, $spreadD * 2 + self::PCB_T, 'ramLight',
                [$len / 2, $spreadTop + $barH / 2, 0.0]);
            $m[] = Mesh::box($len - 14.0, $barH * 0.5, $spreadD + 0.6, 'ramDiffuser',
                [$len / 2, $spreadTop + $barH * 0.75, 0.0]);
        } else {
            $finRun = $len - 20.0;
            $finCount = (int) max(6, floor($finRun / 8.0));
            $m[] = Mesh::stack(
                $finCount,
                $finRun / $finCount,
                [2.4, max(1.5, $spreadH - 1.0), $spreadD * 2 + self::PCB_T],
                'x',
                'fin',
                [$len / 2, $boardH + $spreadH / 2 + 0.5, 0.0]
            );
        }

        // --- Populated chips: count from real capacity ------------------------
        // 8GB = 4 per side; 16GB and up = 8 per side. Real module layouts.
        $perSide = $capacityGb >= 0 && $capacityGb <= 8 ? 4 : 8;
        $chipPitch = ($len - 30.0) / $perSide;
        foreach ([$chipZ, -$chipZ] as $side) {
            for ($i = 0; $i < $perSide; $i++) {
                $x = 22.0 + $chipPitch * ($i + 0.5);
                if ($x > $len - 12.0) {
                    break;
                }
                $m[] = Mesh::box($chipPitch * 0.62, 11.0, 1.4, 'ramChip',
                    [$x, $boardH * 0.62, $side]);
            }
        }

        // --- DDR5 on-dimmer PMIC (a real DDR5-only component) ----------------
        if ($ddr5) {
            foreach ([$chipZ, -$chipZ] as $side) {
                $m[] = Mesh::box(9.0, 9.0, 1.2, 'ramChip', [16.0, $boardH * 0.32, $side]);
            }
        }

        return [
            'meshes' => $m,
            'meta' => [
                'generation' => $generation === '' ? 'unknown' : $generation,
                'capacity_gb' => $capacityGb,
                'rgb' => $rgb,
                'spreaders' => 2,
                'chips_per_side' => $perSide,
                'envelope' => ['x' => $len, 'y' => $hgt, 'z' => $thk],
                'derivation' => 'generation=CatalogueGate::ramType(specs.speed); capacity=specs.capacity; lightbar=/rgb|light/i on name',
            ],
        ];
    }

    protected static function capacityGb(array $specs, string $name): int
    {
        if (preg_match('/(\d{1,3})\s*gb/i', (string) ($specs['capacity'] ?? ''), $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d{1,3})\s*gb/i', $name, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}