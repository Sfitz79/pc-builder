<?php

namespace App\Services\ThreeD;

/**
 * PROCEDURAL CPU + INTEGRATED HEAT SPREADER.
 *
 * LOCAL FRAME (mm): +X = package width, +Y = package height, +Z = thickness with
 * 0 at the board-contact face and +Z toward the cooler.
 *
 * The generator deliberately fills only the top-left ~40 x 40 x 7mm of the
 * published envelope rather than the whole of it. That is not laziness: a CPU
 * package IS 40 x 40mm with a ~7mm integrated heat spreader, and PartDimensions'
 * 90mm CPU default is a long-standing placeholder that describes no real part.
 * Drawing a 90mm slab would be the box this project exists to replace. The
 * generator stays inside the envelope and records the real IHS size in meta so
 * the discrepancy is visible rather than papered over -" see the note in
 * docs/3d-model-phase2.md for the PartDimensions fix that should follow.
 *
 * No IHS engraving, no brand text. A blank heat spreader.
 */
final class CpuGeometry implements PartGeometry
{
    /** Real IHS/package measurements. */
    private const IHS = 40.0;       // integrated heat spreader footprint, mm
    private const IHS_T = 5.6;      // IHS plate thickness, mm
    private const SUBSTRATE_T = 1.4;
    private const IHS_TOTAL = self::IHS_T + self::SUBSTRATE_T;

    public function localFrame(): string
    {
        return 'CPU local: +X width, +Y height, +Z thickness (0=board contact face)';
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public function anchors(array $dims, array $specs, string $name, string $category): array
    {
        $maxX = (float) ($dims['x'] ?? self::IHS);
        $maxY = (float) ($dims['y'] ?? 90.0);
        $size = min(self::IHS, $maxX, $maxY);

        return ['top_centre' => [$size / 2, $size / 2, self::IHS_TOTAL]];
    }

    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array
    {
        // Envelope ceiling from PartDimensions; the package never exceeds it.
        $maxX = (float) ($dims['x'] ?? self::IHS);
        $maxY = (float) ($dims['y'] ?? 90.0);
        $maxZ = (float) ($dims['z'] ?? self::IHS_TOTAL);

        // Never draw bigger than the published envelope.
        $size = min(self::IHS, $maxX, $maxY);
        $t = min(self::IHS_TOTAL, $maxZ);

        $cx = $size / 2;
        $cy = $size / 2;

        $m = [];

        // Green/black FR4 substrate.
        $m[] = Mesh::box($size, $size, self::SUBSTRATE_T, 'cpuSubstrate', [$cx, $cy, self::SUBSTRATE_T / 2]);

        // Nickel-plated IHS with a stepped edge, so it reads as two materials
        // rather than one block.
        $m[] = Mesh::box($size, $size, self::IHS_T, 'ihs', [$cx, $cy, self::SUBSTRATE_T + self::IHS_T / 2]);
        $m[] = Mesh::box($size - 2.0, $size - 2.0, 0.8, 'ihsStep',
            [$cx, $cy, self::SUBSTRATE_T + self::IHS_T + 0.4]);

        // Pin-1 corner triangle marker: a real alignment cue, and the only
        // marking on the part. No lettering.
        $m[] = Mesh::cyl(2.2, 0.6, 'z', 'ihsStep', 3,
            [$size - 6.0, 6.0, self::SUBSTRATE_T + self::IHS_T + 0.8]);

        // The socket's load plate frame around the package. Sized to the package
        // itself: an IHS is 40mm across, so a load plate "ring" drawn as
        // package+8mm radius would claim a 96mm CPU.
        $m[] = Mesh::ring($size / 2, max(2.0, $size / 2 - 2.5), 1.6, 'z', 'socketFrame', 4,
            [$cx, $cy, self::SUBSTRATE_T + self::IHS_T + 0.2]);

        return [
            'meshes' => $m,
            'meta' => [
                'package_mm' => ['x' => round($size, 2), 'y' => round($size, 2), 'z' => round($t, 2)],
                'envelope' => ['x' => $maxX, 'y' => $maxY, 'z' => $maxZ],
                // Announced, not hidden: the published envelope is far larger
                // than any real CPU package. The customer-facing dims list still
                // shows it, so this has to be a visible finding, not a secret.
                'envelope_exceeds_package' => ($maxY > self::IHS + 1.0 || $maxX > self::IHS + 1.0),
                'derivation' => 'package=40x40x7.0mm IHS constant, clamped to the PartDimensions envelope',
            ],
        ];
    }
}