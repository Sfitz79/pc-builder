<?php

namespace App\Services\ThreeD;

/**
 * The procedural geometry vocabulary.
 *
 * Every generator in this namespace returns a plain array of primitives in REAL
 * MILLIMETRES. Nothing here knows about WebGL, and nothing here is random: the
 * same inputs always produce a byte-identical spec, which is what makes the
 * same product render identically on every visit (see
 * scripts/verify-3d-geometry.php, which hashes the output to prove it).
 *
 * Primitive shapes:
 *   box    size [w,h,d]                     position [x,y,z]  rotation [rx,ry,rz]
 *   cyl    radius, height, segments, axis   position           rotation
 *   ring   outer, inner, height, segments   axis               position
 *   stack  count, pitch, plate [w,h,d]      axis, centred      position
 *   fan    radius, blades, bladeLength...   position           rotation
 *   tube   radius, points [[x,y,z] ...]     (client splines it)
 *
 * Positions are part-LOCAL millimetres with the origin described in each
 * generator's docblock. BuildSceneService is the only thing that knows where
 * those local frames sit inside the case.
 *
 * Material keys are symbolic. The client owns the palette, so the spec stays
 * small enough to cache and no colour logic leaks into the geometry.
 */
final class Mesh
{
    /** Positions and sizes are rounded here, once, so the spec is byte-stable. */
    public static function mm(float|int $v): float
    {
        return round((float) $v, 2);
    }

    /** @return array<string, mixed> */
    public static function box(float|int $w, float|int $h, float|int $d, string $material, array $pos = [0, 0, 0], array $rot = [0, 0, 0]): array
    {
        return [
            'p' => 'box',
            's' => [self::mm($w), self::mm($h), self::mm($d)],
            't' => self::point($pos),
            'r' => self::rot($rot),
            'm' => $material,
        ];
    }

    /**
     * @param  string  $axis  x|y|z - the direction the cylinder's height runs.
     */
    public static function cyl(float|int $radius, float|int $height, string $axis, string $material, int $segments = 24, array $pos = [0, 0, 0], array $rot = [0, 0, 0]): array
    {
        return [
            'p' => 'cyl',
            'r' => self::mm($radius),
            'h' => self::mm($height),
            'seg' => $segments,
            'ax' => $axis,
            't' => self::point($pos),
            'rr' => self::rot($rot),
            'm' => $material,
        ];
    }

    /** A hollow ring (tube) - fan apertures, fan frames, grills. */
    public static function ring(float|int $outer, float|int $inner, float|int $height, string $axis, string $material, int $segments = 28, array $pos = [0, 0, 0], array $rot = [0, 0, 0]): array
    {
        return [
            'p' => 'ring',
            'o' => self::mm($outer),
            'i' => self::mm($inner),
            'h' => self::mm($height),
            'seg' => $segments,
            'ax' => $axis,
            't' => self::point($pos),
            'rr' => self::rot($rot),
            'm' => $material,
        ];
    }

    /**
     * A repeated array of identical plates - GPU fin stacks, heatsink fins,
     * DIMM slot ridges. Repeated in PHP rather than in the browser so the spec
     * stays small and so the count is provably derived from real dimensions.
     *
     * @param  string  $axis     x|y|z - the direction plates march along.
     * @param  bool    $centred  centre the run on the origin rather than starting at it.
     */
    public static function stack(int $count, float|int $pitch, array $plate, string $axis, string $material, array $pos = [0, 0, 0], bool $centred = true, array $rot = [0, 0, 0]): array
    {
        return [
            'p' => 'stack',
            'n' => max(0, $count),
            'pitch' => self::mm($pitch),
            'plate' => array_map(self::mm(...), array_values($plate)),
            'ax' => $axis,
            't' => self::point($pos),
            'c' => $centred,
            'rr' => self::rot($rot),
            'm' => $material,
        ];
    }

    /**
     * A fan rotor. The client builds the blades and registers the group for
     * animation, so a fan is one spec entry rather than nine.
     *
     * `d` is the blade stack's depth along its own axis - a real axial fan is
     * 25-38mm deep and its rotor is much thinner. Without an explicit depth a
     * rotor's bounding box is its radius deep, which makes a 90mm fan 90mm
     * thick and punches it straight through the card it is bolted to.
     *
     * The rotor is always built in the XY plane spinning about local +Z; `rot`
     * is what orients it into place.
     *
     * @param  string  $pitch  blade count; real fans are 7/9/11/13 blades.
     */
    public static function fan(float|int $radius, int $blades, string $axis, array $pos = [0, 0, 0], array $rot = [0, 0, 0], ?string $bladeMaterial = null, float|int $hubRatio = 0.3, ?float $depth = null): array
    {
        $r = (float) $radius;
        $d = $depth !== null ? (float) $depth : min(9.0, max(3.0, $r * 0.10));

        return [
            'p' => 'fan',
            'r' => self::mm($r),
            'd' => self::mm($d),
            'n' => max(3, $blades),
            'ax' => $axis,
            't' => self::point($pos),
            'rr' => self::rot($rot),
            'bm' => $bladeMaterial ?? 'fanBlade',
            'hub' => self::mm($hubRatio),
        ];
    }

    /** A sleeved/routed cable run. The client splines the control points. */
    public static function tube(float|int $radius, array $points, string $material = 'cable'): array
    {
        return [
            'p' => 'tube',
            'r' => self::mm($radius),
            'pts' => array_map(self::point(...), array_values($points)),
            'm' => $material,
        ];
    }

    /** @return array{0: float, 1: float, 2: float} */
    protected static function point(array $p): array
    {
        return [
            self::mm($p[0] ?? 0),
            self::mm($p[1] ?? 0),
            self::mm($p[2] ?? 0),
        ];
    }

    /** @return array{0: float, 1: float, 2: float} */
    protected static function rot(array $r): array
    {
        return [
            self::mm($r[0] ?? 0),
            self::mm($r[1] ?? 0),
            self::mm($r[2] ?? 0),
        ];
    }

    /** Degrees to radians, rounded, so angles are as stable as the distances. */
    public static function rad(float $degrees): float
    {
        return round(deg2rad($degrees), 4);
    }

    /**
     * Annotate a primitive without leaving the vocabulary.
     *
     * `protrudes` marks the handful of parts that physically stick out beyond
     * the envelope PartDimensions publishes - a GPU's PCIe edge connector and
     * its anti-sag bracket are the real examples. The verifier holds everything
     * else to the published millimetres and only forgives annotated parts, so a
     * generator cannot quietly inflate a component's advertised size.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public static function flag(array $m, string $key, mixed $value): array
    {
        $m[$key] = $value;

        return $m;
    }
}