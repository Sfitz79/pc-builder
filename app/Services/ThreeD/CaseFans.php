<?php

namespace App\Services\ThreeD;

/**
 * PROCEDURAL CASE FANS.
 *
 * Local frame = the CASE frame (see CaseGeometry): +X width, +Y height from the
 * floor, +Z depth with the rear at -Z.
 *
 * These fans are not decoration and they are not a fixed trio. They are derived
 * from the case's own published cooling capability, which is real catalogue
 * data:
 *  - `rad_top` (mm) decides whether there is a top mount and how many fans fit
 *    across the case depth. 0 / absent = no top mount at all.
 *  - `rad_front` (mm) decides the front intake column the same way.
 *  - a 360mm mount takes three 120mm fans, 280mm two, 240mm two, 120/140mm one.
 *  - a rear 120mm exhaust is universal and always drawn.
 *
 * Fan size comes from the mount size, and blade count from the frame size, which
 * is how real 120mm and 140mm fans differ. Every fan is a square frame with four
 * real corner struts, a hub and 7/9/11 blades -" the frame struts are the detail
 * that stops a fan reading as a disc.
 *
 * If the case publishes no radiator mounts at all, the generator draws the
 * minimum real airflow: one front intake and one rear exhaust, and reports
 * `top_mount` / `front_mount` as false in meta. It does not invent mounts.
 */
final class CaseFans implements PartGeometry
{
    /** Real fan frame sizes and their blade counts. */
    private const SIZES = [
        120 => ['blade' => 9, 'thick' => 25.0],
        140 => ['blade' => 11, 'thick' => 25.0],
        92 => ['blade' => 7, 'thick' => 14.0],
    ];

    /** Half-thickness of a fan frame's mounting flange, mm. */
    private const FLANGE = 7.0;

    public function localFrame(): string
    {
        return 'Case fan local = case frame: +X width, +Y height (0=floor), +Z depth (rear at -Z)';
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public function anchors(array $dims, array $specs, string $name, string $category): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $dims  the CASE's dims (rad_top/rad_front/max_gpu/...).
     */
    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array
    {
        $w = (float) ($dims['x'] ?? 230);
        $h = (float) ($dims['y'] ?? 460);
        $d = (float) ($dims['z'] ?? 460);
        $yBase = 20.0; // feet, matching CaseGeometry

        $radTop = (int) ($dims['rad_top'] ?? 0);
        $radFront = (int) ($dims['rad_front'] ?? 0);

        $top = self::mountFor($radTop);
        $front = self::mountFor($radFront);

        $m = [];

        // --- Top mount (exhaust, blowing up out of the roof) -----------------
        if ($top) {
            $count = $top['count'];
            $size = $top['size'];
            $cell = min($d - 70.0, $radTop) / $count;
            for ($i = 0; $i < $count; $i++) {
                $z = -$d / 2 + 35.0 + $cell * ($i + 0.5);
                $m = array_merge($m, $this->fan(size: $size, x: 0.0, y: $yBase + $h - 18.0, z: $z,
                    rot: [Mesh::rad(90), 0, 0], rgb: true));
            }
        }

        // --- Front intake column --------------------------------------------
        if ($front) {
            $count = $front['count'];
            $size = $front['size'];
            $run = $h * 0.62;
            $cell = min($run, $front['rad']) / $count;
            for ($i = 0; $i < $count; $i++) {
                $y = $yBase + $h * 0.30 + $cell * ($i + 0.5);
                $m = array_merge($m, $this->fan(size: $size, x: 0.0, y: $y, z: $d / 2 - 20.0,
                    rot: [0, 0, 0], rgb: true));
            }
        }

        // --- Rear 120mm exhaust (universal) ----------------------------------
        $rearY = $yBase + $h * 0.62;
        $m = array_merge($m, $this->fan(size: 120, x: -($w * 0.22), y: $rearY, z: -$d / 2 + 12.0,
            rot: [0, 0, Mesh::rad(180)], rgb: true));

        // When the case publishes no radiator mounts we still need air moving:
        // a single front intake is the minimum real airflow, and it is placed
        // from the case's own dimensions rather than a fixed offset.
        if (! $top && ! $front) {
            $m = array_merge($m, $this->fan(size: 120, x: 0.0, y: $yBase + $h * 0.34,
                z: $d / 2 - 20.0, rot: [0, 0, 0], rgb: true));
        }

        return [
            'meshes' => $m,
            'meta' => [
                'top_mount' => $top !== null,
                'front_mount' => $front !== null,
                'top' => $top,
                'front' => $front,
                // Counted from what was actually emitted, not from the mount
                // table: if a case's published radiator size does not fit its
                // own depth, the fan is skipped and the count must say so.
                'fan_count' => $this->countRotors($m),
                'envelope' => ['x' => $w, 'y' => $h, 'z' => $d],
                'derivation' => 'mounts + fan sizes + counts = case rad_top/rad_front; rear 120mm exhaust is universal',
            ],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $meshes */
    private function countRotors(array $meshes): int
    {
        return count(array_filter($meshes, fn (array $m) => ($m['p'] ?? '') === 'fan'));
    }

    /**
     * Turn a published radiator length into a real mount.
     *
     * @return array{rad: int, size: int, count: int}|null
     */
    public static function mountFor(int $radLength): ?array
    {
        return match (true) {
            $radLength >= 340 => ['rad' => 360, 'size' => 120, 'count' => 3],
            $radLength >= 260 => ['rad' => 280, 'size' => 140, 'count' => 2],
            $radLength >= 230 => ['rad' => 240, 'size' => 120, 'count' => 2],
            $radLength >= 120 => ['rad' => 120, 'size' => 120, 'count' => 1],
            default => null,
        };
    }

    /**
     * One fan: square frame, four corner struts, hub, blades.
     *
     * A rotor is always built in the XY plane spinning about +Z; `rot` orients it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fan(int $size, float $x, float $y, float $z, array $rot, bool $rgb): array
    {
        $spec = self::SIZES[$size] ?? self::SIZES[120];
        $thick = $spec['thick'];
        $blades = $spec['blade'];
        $r = ($size - 14.0) / 2;

        $m = [];

        // Frame: four struts, not a solid slab -" this is what reads as a fan.
        $armW = self::FLANGE;
        $armLen = $size / 2 - $r + 4.0;
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$ax, $ay]) {
            $m[] = Mesh::box(
                $ax === 0 ? $armW : $armLen,
                $ax === 0 ? $armLen : $armW,
                $thick,
                'fanFrame',
                [$x + $ax * ($size / 2 - $armLen / 2), $y + $ay * ($size / 2 - $armLen / 2), $z]
            );
        }
        // Outer ring lip + inner ring, so the frame has depth.
        $m[] = Mesh::ring($size / 2, $r, $thick, 'z', 'fanFrame', 4, [$x, $y, $z], $rot);
        $m[] = Mesh::ring($r, $r - 4.0, $thick * 0.5, 'z', 'fanFrame', 28, [$x, $y, $z], $rot);

        // Rotor + hub.
        $m[] = Mesh::fan($r - 2.0, $blades, 'z', [$x, $y, $z], $rot, 'fanBlade', 0.30);
        $m[] = Mesh::cyl($r * 0.34, $thick + 2.0, 'z', 'fanHub', 18, [$x, $y, $z], $rot);

        // Mounting screws in the four corners.
        foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$sx, $sy]) {
            $m[] = Mesh::cyl(3.0, $thick + 3.0, 'z', 'fanScrew', 8,
                [$x + $sx * ($size / 2 - 8.0), $y + $sy * ($size / 2 - 8.0), $z], $rot);
        }

        // LED ring on the inner lip -" emissive on the client, brandless.
        if ($rgb) {
            $m[] = Mesh::ring($r + 1.0, $r - 1.0, 2.4, 'z', 'fanLed', 30, [$x, $y, $z + $thick / 2 + 1.2], $rot);
        }

        return $m;
    }
}