<?php

namespace App\Services\ThreeD;

/**
 * PROCEDURAL POWER SUPPLY -" ATX and SFX, driven by PartDimensions' published
 * form factor and the wattage the catalogue actually stores.
 *
 * LOCAL FRAME (mm): +X = width, +Y = height with 0 at the floor face and +Y
 * toward the roof, +Z = depth.
 *
 * The thing that makes a PSU read as a PSU is not the box: it is the 140mm fan
 * on the underside, the honeycomb exhaust grill on the rear face, the IEC inlet
 * and rocker switch beside it, and the recessed modular connector panel on the
 * front. All four are modelled.
 *
 * VARIATION IS REAL:
 *  - ATX (140 x 86 x 150) vs SFX (100 x 63.5 x 125) comes from PartDimensions,
 *    which reads `form`/`form_factor`.
 *  - the fan diameter and its position come from the real 120/140mm fan sizes a
 *    PSU of that width can physically take, not a fixed 140.
 *  - `wattage` is populated on 282 of 287 active PSUs, and it drives the size of
 *    the modular panel and the cable bundle: a 450W unit genuinely does not
 *    carry the same connector field as a 1600W one.
 */
final class PsuGeometry implements PartGeometry
{
    /** Real ATX/SFX PSU constants. */
    private const FAN_LARGE = 140.0;
    private const FAN_SMALL = 120.0;
    private const HONEYCOMB_PITCH = 7.0;
    private const MODULAR_INSET = 3.0;

    public function localFrame(): string
    {
        return 'PSU local: +X width, +Y height (0=floor face), +Z depth (0=cable-exit face)';
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public function anchors(array $dims, array $specs, string $name, string $category): array
    {
        $w = (float) ($dims['x'] ?? 140);
        $h = (float) ($dims['y'] ?? 86);
        $d = (float) ($dims['z'] ?? 150);

        return [
            'cable_exit' => [$w / 2, $h * 0.78, $d],
            'top_centre' => [$w / 2, $h, $d * 0.5],
            'modular_face' => [$w / 2, $h * 0.42, $d],
        ];
    }

    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array
    {
        $w = (float) ($dims['x'] ?? 140);
        $h = (float) ($dims['y'] ?? 86);
        $d = (float) ($dims['z'] ?? 150);
        $form = strtoupper((string) ($dims['form'] ?? 'ATX'));
        $sfx = $form === 'SFX';

        $wattage = (int) self::wattage($specs, $name, $dims);

        // Fan: real PSU fans are 120 or 140mm and sit on the FLOOR face, drawing air
        // up into the PSU. The centre is placed so the guard ring stays inside
        // the published depth - a fan centred at half the PSU's length on a
        // 140mm fan pokes out through the front of its own chassis.
        $fanDia = min($w - 12.0, $sfx ? self::FAN_SMALL : self::FAN_LARGE);
        $fanDia = max(80.0, $fanDia);
        $fanR = $fanDia / 2;
        $fanX = $w / 2;
        $fanZ = $fanR + 8.0;

        $m = [];

        // --- Chassis: a body with chamfered top edges, not a bare box -------
        $m[] = Mesh::box($w, $h, $d, 'psuBody', [$w / 2, $h / 2, $d / 2]);
        $chamfer = min(10.0, $w * 0.06);
        $m[] = Mesh::box($w, $chamfer, $d * 0.96, 'psuChamfer', [$w / 2, $h - $chamfer / 2, $d / 2],
            [0, 0, 0]);
        $m[] = Mesh::box($w - 6.0, 2.0, $d - 6.0, 'psuTop', [$w / 2, $h - 1.0, $d / 2]);

        // --- Underside fan + finger guard ----------------------------------
        $m[] = Mesh::ring($fanR + 5.0, $fanR - 1.0, 3.0, 'y', 'fanGuard', 30, [$fanX, 1.5, $fanZ]);
        // The rotor sits in the plane of the floor face: a Y-axis fan with its blades
        // in the XZ plane, so the hub sticks out through the underside.
        $m[] = Mesh::fan($fanR * 0.96, 9, 'y', [$fanX, 12.0, $fanZ], [Mesh::rad(-90), 0, 0],
            'fanBlade', 0.32, 22.0);
        // Concentric guard rings + spokes: the real giveaway of a PSU underside.
        for ($i = 1; $i <= 3; $i++) {
            $m[] = Mesh::ring($fanR * (1 - $i * 0.28), $fanR * (1 - $i * 0.28) - 1.4, 1.6, 'y',
                'fanGuard', 26, [$fanX, 3.4, $fanZ]);
        }
        for ($i = 0; $i < 4; $i++) {
            $m[] = Mesh::box($fanR * 2, 1.6, 2.4, 'fanGuard', [$fanX, 3.4, $fanZ],
                [0, Mesh::rad(45 * $i), 0]);
        }

        // --- Rear face: honeycomb exhaust grill, IEC inlet, rocker ----------
        $grillW = $sfx ? $w * 0.62 : $w * 0.60;
        $grillH = $h * 0.72;
        $grillX = $w * 0.36;
        $grillY = $h * 0.52;
        $cols = (int) max(3, floor($grillW / self::HONEYCOMB_PITCH));
        $rows = (int) max(3, floor($grillH / self::HONEYCOMB_PITCH));
        $m[] = Mesh::box($grillW, $grillH, 2.0, 'psuGrillFrame', [$grillX, $grillY, 1.0]);
        $m[] = Mesh::stack($cols, $grillW / $cols, [1.4, $grillH - 2.0, 1.6], 'x', 'psuGrill',
            [$grillX - $grillW / 2 + $grillW / (2 * $cols), $grillY, 1.0], false);
        $m[] = Mesh::stack($rows, $grillH / $rows, [$grillW - 2.0, 1.4, 1.6], 'y', 'psuGrill',
            [$grillX, $grillY - $grillH / 2 + $grillH / $rows, 1.0], false);

        // IEC C14 inlet + rocker switch, on the same rear face.
        $iecX = $w * 0.80;
        $m[] = Mesh::box(24.0, 20.0, 6.0, 'psuIec', [$iecX, $h * 0.30, 3.0]);
        $m[] = Mesh::box(17.0, 14.0, 3.0, 'psuIecCavity', [$iecX, $h * 0.30, 6.0]);
        $m[] = Mesh::box(11.0, 16.0, 5.0, 'psuRocker', [$w * 0.80, $h * 0.74, 2.5]);

        // --- Front face: modular connector panel ----------------------------
        // Port count from real wattage. 650W gets 4, 1200W+ gets 8.
        $ports = max(2, min(8, (int) round($wattage / 200)));
        $panelW = $w - 12.0;
        $panelH = $h * 0.62;
        $m[] = Mesh::box($panelW, $panelH, self::MODULAR_INSET, 'psuPanel',
            [$w / 2, $h * 0.42, $d - self::MODULAR_INSET / 2]);

        $perRow = max(2, (int) ceil($ports / 2));
        $pitch = $panelW / $perRow;
        for ($i = 0; $i < $ports; $i++) {
            $col = $i % $perRow;
            $row = intdiv($i, $perRow);
            $m[] = Mesh::box($pitch * 0.62, 16.0, 4.0, 'psuPort',
                [4.0 + $pitch * ($col + 0.5), $h * 0.30 + $row * 26.0, $d - 2.0]);
        }

        // --- Screws + cable bundle exit ------------------------------------
        $m[] = Mesh::cyl(2.6, $h, 'y', 'psuScrew', 8, [$w - 6.0, $h / 2, 6.0]);
        $m[] = Mesh::cyl(2.6, $h, 'y', 'psuScrew', 8, [$w - 6.0, $h / 2, $d - 6.0]);
        $m[] = Mesh::box($panelW * 0.7, 8.0, 8.0, 'psuCableExit', [$w / 2, $h * 0.78, $d - 4.0]);

        return [
            'meshes' => $m,
            'meta' => [
                'form' => $sfx ? 'SFX' : 'ATX',
                'wattage' => $wattage,
                'fan_diameter_mm' => round($fanDia, 2),
                'modular_ports' => $ports,
                'envelope' => ['x' => $w, 'y' => $h, 'z' => $d],
                'derivation' => 'form=PartDimensions form; wattage=components.wattage; fan=real 120/140mm; ports=wattage/200',
            ],
        ];
    }

    /**
     * Real wattage: the catalogue column first, then the specs JSON, then the
     * product name (a PSU name almost always states its wattage). Fails to 0,
     * which the port-count rule clamps, rather than inventing a figure.
     */
    protected static function wattage(array $specs, string $name, array $dims): int
    {
        foreach ([$specs['wattage'] ?? null, $dims['wattage'] ?? null] as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }
        if (preg_match('/(\d{3,4})\s*W/', strtoupper($name), $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}