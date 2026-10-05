<?php

namespace App\Services\ThreeD;

/**
 * PROCEDURAL CPU COOLER - air tower and 120/240/280/360mm AIO.
 *
 * TWO LOCAL FRAMES, because a cooler genuinely has two parts that live in
 * different places:
 *
 *  - AIR TOWER: local frame is the cooler itself. +X = base width (125mm real),
 *    +Y = height (160mm real), +Z = base depth (135mm real). The scene applies
 *    the basis that stands it up on the socket: +Y becomes "out of the board,
 *    toward the case glass", which is why a 160mm tower really does eat 160mm
 *    of case clearance and why `max_cpu_cooler_height` matters.
 *  - AIO: the radiator and the pump are emitted as separate groups already in
 *    CASE space, because a liquid cooler's radiator mounts on the case roof and
 *    its pump block mounts on the CPU. Faking that with one frame produced a
 *    pump floating in the roof.
 *
 * WHICH COOLER IS DRAWN is decided by PartDimensions, which already reads the
 * real `type` spec and the real radiator length: a 360 AIO and a 160mm tower
 * are different objects, not the same object rescaled.
 *
 * VARIATION IS THE REAL DIMENSION, NOT A SCALE FACTOR:
 *  - tower height drives the fin-plate count (real plate pitch 2.2mm)
 *  - height over 150mm means a dual tower, which really means two fin stacks
 *    and a fan between them, not one fatter stack
 *  - radiator length and fan_count are real and drive the AIO's body and fans
 *  - fan size follows the mount: 360 -> three 120mm, 280 -> two 140mm,
 *    240 -> two 120mm, 120 -> one 120mm
 */
final class CoolerGeometry implements PartGeometry
{
    private const FIN_PITCH = 2.2;      // real tower fin plate pitch, mm
    private const BASE_H = 20.0;        // contact plate + heatpipe bend, mm
    private const DUAL_TOWER_H = 150.0; // above this a tower is dual-finned
    private const PUMP = 66.0;          // real pump block footprint, mm
    private const RAD_CORE_T = 27.0;    // real radiator core thickness, mm

    public function localFrame(): string
    {
        return 'Air tower local: +X base width, +Y height (0=contact plate), +Z base depth. '
            .'AIO returns case-space groups instead (radiator on the roof, pump on the CPU).';
    }

    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array
    {
        if (($dims['type'] ?? 'air') === 'aio') {
            return $this->aio($dims, $name, $context);
        }

        return $this->air($dims, $name);
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public function anchors(array $dims, array $specs, string $name, string $category): array
    {
        $h = (float) ($dims['y'] ?? 160);

        return ['top_centre' => [(float) ($dims['x'] ?? 125) / 2, $h, (float) ($dims['z'] ?? 135) / 2]];
    }

    /**
     * Air tower. The fin stack is a plate stack in +Y, which is how a real tower
     * cooler is built, and it is what makes the silhouette read.
     *
     * @return array<string, mixed>
     */
    private function air(array $dims, string $name): array
    {
        $w = (float) ($dims['x'] ?? 125);
        $h = (float) ($dims['y'] ?? 160);
        $d = (float) ($dims['z'] ?? 135);

        $dual = $h >= self::DUAL_TOWER_H;
        $towers = $dual ? 2 : 1;
        $towerW = $dual ? ($w * 0.42) : $w;

        $m = [];

        // Contact plate + heatpipe bend.
        $m[] = Mesh::box($w, self::BASE_H * 0.6, $d, 'coolerBase', [$w / 2, self::BASE_H * 0.3, $d / 2]);
        $m[] = Mesh::box($w * 0.9, 2.0, $d * 0.9, 'nickel', [$w / 2, 1.0, $d / 2]);

        // Heatpipes rising out of the base into the fin stack. Six on a dual
        // tower, four on a single - the real counts.
        $pipes = $dual ? 6 : 4;
        foreach ($this->pipePositions($w, $d, $pipes) as [$px, $pz]) {
            $m[] = Mesh::cyl(3.0, $h * 0.62, 'y', 'heatpipe', 12,
                [$px, self::BASE_H * 0.6 + $h * 0.31, $pz]);
        }

        // Fin stacks. Plate count falls straight out of the real height.
        $finRun = max(20.0, $h - self::BASE_H - 8.0);
        $finCount = (int) max(8, floor($finRun / self::FIN_PITCH));
        for ($t = 0; $t < $towers; $t++) {
            $tx = $dual ? ($w * 0.25 + $t * $w * 0.34) : ($w / 2);
            $m[] = Mesh::stack(
                $finCount,
                $finRun / $finCount,
                [$towerW, 0.6, $d * 0.92],
                'y',
                'fin',
                [$tx, self::BASE_H + $finRun / 2, $d / 2]
            );
            // Fin stack edge cap so the stack does not read as loose plates.
            $m[] = Mesh::box($towerW, $finRun, 1.6, 'coolerTrim',
                [$tx, self::BASE_H + $finRun / 2, $d - $d * 0.46]);
        }

        // Top cover plate with a plain badge recess. Never any text.
        $m[] = Mesh::box($w * 0.98, 3.0, $d * 0.94, 'coolerTop', [$w / 2, $h - 1.5, $d / 2]);
        $m[] = Mesh::cyl(9.0, 1.2, 'y', 'coolerTop', 16, [$w / 2, $h - 0.6, $d / 2]);

        // Fans on the face that blows through the fins: one on a single tower,
        // two on a dual tower. A rotor is built in the XY plane spinning about
        // +Z, so a cooler fan facing -Z needs no rotation. Both sit INSIDE the
        // published base depth rather than hanging off the front of it.
        $fanY = $dual ? $h * 0.52 : $h * 0.44;
        $fanR = min(58.0, $h * 0.34, $d * 0.32);
        $fanZ = 13.0;
        $m[] = Mesh::fan($fanR, 9, 'z', [$w / 2, $fanY, $fanZ], [0, 0, 0], 'fanBlade', 0.28, 22.0);
        $m[] = Mesh::ring($fanR + 4.0, $fanR - 1.0, 25.0, 'z', 'fanFrame', 26, [$w / 2, $fanY, $fanZ]);
        if ($dual) {
            $m[] = Mesh::fan($fanR, 9, 'z', [$w / 2, $fanY, $d - $fanZ], [0, 0, 0], 'fanBlade', 0.28, 22.0);
            $m[] = Mesh::ring($fanR + 4.0, $fanR - 1.0, 25.0, 'z', 'fanFrame', 26,
                [$w / 2, $fanY, $d - $fanZ]);
        }

        // Mounting bracket and spring screws.
        $m[] = Mesh::box(18.0, 4.0, $d * 0.5, 'coolerMount', [$w / 2, 4.0, $d / 2]);
        $m[] = Mesh::cyl(4.0, 26.0, 'y', 'coolerMount', 12, [$w * 0.16, 13.0, $d / 2]);
        $m[] = Mesh::cyl(4.0, 26.0, 'y', 'coolerMount', 12, [$w * 0.84, 13.0, $d / 2]);

        return [
            'meshes' => $m,
            'meta' => [
                'kind' => 'air',
                'space' => 'part',
                'towers' => $towers,
                'fans' => $dual ? 2 : 1,
                'fin_plates' => $finCount,
                'height_mm' => round($h, 2),
                'envelope' => ['x' => $w, 'y' => $h, 'z' => $d],
                'derivation' => 'kind=PartDimensions type; dual-tower=height>=150mm; fin plates=height/2.2mm',
            ],
        ];
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function pipePositions(float $w, float $d, int $pipes): array
    {
        $out = [];
        for ($i = 0; $i < $pipes; $i++) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $out[] = [$w * 0.28 + $col * $w * 0.16 + $row * ($w * 0.10), $d * 0.24 + $row * $d * 0.26];
        }

        return $out;
    }

    /**
     * AIO liquid cooler. Two case-space groups: the radiator on the roof and
     * the pump block on the CPU. The tube run is a real sleeved pair between
     * them, not a decorative box.
     *
     * @return array<string, mixed>
     */
    private function aio(array $dims, string $name, array $context): array
    {
        $radLen = (float) ($dims['radiator_length'] ?? ($dims['x'] ?? 360));
        $fanCount = max(1, (int) ($dims['fan_count'] ?? 3));

        // Real fan size for a real mount length.
        $fanSize = match (true) {
            $radLen >= 330 => 120,
            $radLen >= 260 => 140,
            default => 120,
        };

        $caseDims = $context['case'] ?? ['x' => 230, 'y' => 460, 'z' => 460];
        $caseD = (float) ($caseDims['z'] ?? 460);
        $roofY = (float) ($context['roof_y'] ?? 480);
        $socket = $context['socket_case'] ?? [0.0, 200.0, 0.0];

        // Radiator sits flat against the roof, long axis front-to-back (case Z),
        // which is where a top mount actually is.
        $radZ = min($radLen, $caseD - 40.0);
        $radTopY = $roofY - self::RAD_CORE_T / 2;
        $radCentreZ = -($radLen - $radZ) / 2;   // pull it forward to the roof's centre

        $radM = [];
        $radM[] = Mesh::box($fanSize - 6.0, self::RAD_CORE_T, $radZ, 'radCore',
            [0, $radTopY, $radCentreZ]);
        $radM[] = Mesh::box($fanSize, self::RAD_CORE_T + 2.0, 24.0, 'radTank',
            [0, $radTopY, $radCentreZ - $radZ / 2 + 12.0]);
        $radM[] = Mesh::box($fanSize, self::RAD_CORE_T + 2.0, 24.0, 'radTank',
            [0, $radTopY, $radCentreZ + $radZ / 2 - 12.0]);
        // Side rails on all four long edges, which is what stops a radiator
        // reading as a slab.
        foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as [$sx, $sy]) {
            $radM[] = Mesh::box(4.0, 4.0, $radZ, 'radFrame',
                [$sx * ($fanSize / 2 - 2.0), $radTopY + $sy * (self::RAD_CORE_T / 2 - 2.0), $radCentreZ]);
        }

        // Fin block between the tanks. Radiator fins march along the flow, which
        // runs down through the core.
        $finRun = $radZ - 48.0;
        $finCount = (int) max(8, floor($finRun / 8.0));
        $radM[] = Mesh::stack($finCount, $finRun / $finCount, [$fanSize - 14.0, self::RAD_CORE_T - 6.0, 1.6],
            'z', 'fin', [0, $radTopY, $radCentreZ]);

        // Fans on the underside of the radiator, blowing down into the case.
        $cell = ($radZ - 30.0) / $fanCount;
        $fanY = $radTopY - self::RAD_CORE_T / 2 - 12.5;
        for ($i = 0; $i < $fanCount; $i++) {
            $z = $radCentreZ - $radZ / 2 + 15.0 + $cell * ($i + 0.5);
            $radM[] = Mesh::box(5.0, 25.0, $cell - 5.0, 'fanFrame', [0, $fanY, $z]);
            $radM[] = Mesh::fan($cell * 0.44, $fanSize >= 140 ? 11 : 9, 'y', [0, $fanY, $z],
                [Mesh::rad(90), 0, 0], 'fanBlade', 0.28, 22.0);
            $radM[] = Mesh::ring($cell * 0.44 + 3.0, $cell * 0.44 - 1.0, 25.0, 'y', 'fanFrame', 26,
                [0, $fanY, $z]);
        }

        // Pump block on the CPU: cold plate, motor housing, blank acrylic cap.
        $pumpM = [];
        $pumpM[] = Mesh::box(self::PUMP, 8.0, self::PUMP, 'pumpBlock', [0.0, 4.0, 0.0]);
        $pumpM[] = Mesh::cyl(self::PUMP * 0.38, 26.0, 'y', 'pumpMotor', 24, [0.0, 21.0, 0.0]);
        $pumpM[] = Mesh::cyl(self::PUMP * 0.34, 3.0, 'y', 'pumpCap', 24, [0.0, 35.5, 0.0]);
        $pumpM[] = Mesh::ring(self::PUMP * 0.30, self::PUMP * 0.30 - 2.0, 2.0, 'y', 'pumpCap', 26, [0.0, 37.4, 0.0]);

        // Tubes: two real sleeved runs from the pump up to the radiator's near
        // end tank, routed with a slack loop because that is how they are run.
        $tubeM = [];
        $endZ = $radCentreZ - $radZ / 2 + 12.0;
        $outletY = $radTopY - self::RAD_CORE_T / 2 - 6.0;
        $tubeM[] = Mesh::tube(6.5, [
            [$socket[0] - 20.0, $socket[1] + 30.0, $socket[2]],
            [$socket[0] - 46.0, $socket[1] + 70.0, $socket[2] - 20.0],
            [($socket[0] + 0.0) / 2 - 30.0, ($socket[1] + $outletY) / 2, ($socket[2] + $endZ) / 2 - 10.0],
            [0.0, $outletY, $endZ],
        ], 'aioTube');
        $tubeM[] = Mesh::tube(6.5, [
            [$socket[0] + 20.0, $socket[1] + 30.0, $socket[2]],
            [$socket[0] + 10.0, $socket[1] + 74.0, $socket[2] - 26.0],
            [($socket[0] + 0.0) / 2 - 20.0, ($socket[1] + $outletY) / 2 + 6.0, ($socket[2] + $endZ) / 2 - 18.0],
            [0.0, $outletY - 8.0, $endZ],
        ], 'aioTube');

        return [
            'meshes' => [],
            'groups' => [
                [
                    'key' => 'radiator',
                    'space' => 'case',
                    't' => [0.0, 0.0, 0.0],
                    'meshes' => $radM,
                    'anchors' => ['outlet' => [0.0, $radTopY - self::RAD_CORE_T / 2 - 6.0, $endZ]],
                    // 27mm core + 2mm of end-tank overhang + a 25mm fan = 54mm assembled, which is
                    // what a real 360 AIO stack measures.
                    'envelope' => ['x' => $fanSize, 'y' => self::RAD_CORE_T + 27.0, 'z' => $radZ],
                ],
                [
                    'key' => 'pump',
                    'space' => 'case',
                    't' => [$socket[0], $socket[1], $socket[2]],
                    'meshes' => $pumpM,
                    'anchors' => [],
                    'envelope' => ['x' => self::PUMP, 'y' => 40.0, 'z' => self::PUMP],
                ],
                [
                    'key' => 'tubes',
                    'space' => 'case',
                    't' => [0.0, 0.0, 0.0],
                    'meshes' => $tubeM,
                    'anchors' => [],
                    // No envelope on purpose: a sleeved tube run has no published
                    // dimension, it is routed. Declaring a fake one here would be
                    // inventing a spec, which is the thing this project exists to
                    // stop doing.
                    'envelope' => null,
                ],
            ],
            'meta' => [
                'kind' => 'aio',
                'space' => 'case',
                'radiator_mm' => (int) $radLen,
                'fan_size_mm' => $fanSize,
                'fans' => $fanCount,
                'envelope' => ['x' => $radLen, 'y' => (float) ($dims['y'] ?? 75), 'z' => (float) ($dims['z'] ?? 52)],
                'derivation' => 'radiator length + fan_count = PartDimensions (specs.radiator_length / fan_count); fan size from mount length',
            ],
        ];
    }
}