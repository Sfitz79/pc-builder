<?php

namespace App\Services\ThreeD;

/**
 * PROCEDURAL STORAGE -" M.2 NVMe stick.
 *
 * LOCAL FRAME (mm): +X = stick length, +Y = stick width, +Z = thickness with 0
 * at the PCB and +Z toward the heatsink.
 *
 * NOTE ON THE PUBLISHED ENVELOPE, STATED PLAINLY RATHER THAN PAPERED OVER:
 * PartDimensions publishes storage as 80 x 22 x 3mm. That is precisely the
 * M.2 2280 form factor, and this generator draws exactly that. But the storage
 * category also contains 2.5" SATA SSDs and 3.5" hard drives, whose real sizes
 * are 100 x 70 x 7mm and 101.6 x 146.99 x 26.11mm. The catalogue cannot
 * currently tell them apart: measured 2026-10-05, CatalogueGate::storageType()
 * returns "Unknown" on 335 of 338 active rows because `specs.type` is unrecorded
 * and the model number does not derive the interface safely.
 *
 * So this generator draws the form the published dimensions describe, and sets
 * `envelope_exceeds_package` in meta whenever the part is not provably M.2.
 * Nothing is silently mis-rendered: the limitation is reported, and
 * docs/3d-model-phase2.md records the data fix that would resolve it.
 */
final class StorageGeometry implements PartGeometry
{
    /** Real M.2 constants. */
    private const PCB_T = 1.6;
    private const EDGE_X = 22.0;   // M.2 edge connector length
    private const CHIP_PITCH = 11.0;
    private const CHIP_FIRST_X = 44.0;

    public function localFrame(): string
    {
        return 'Storage local: +X length (0=connector end), +Y width, +Z thickness (0=PCB face)';
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public function anchors(array $dims, array $specs, string $name, string $category): array
    {
        $len = (float) ($dims['x'] ?? 80.0);
        $wid = (float) ($dims['y'] ?? 22.0);

        return ['centre' => [$len / 2, $wid / 2, 0.0]];
    }

    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array
    {
        $len = (float) ($dims['x'] ?? 80.0);
        $wid = (float) ($dims['y'] ?? 22.0);
        $thk = (float) ($dims['z'] ?? 3.0);

        $capacityGb = self::capacityGb($specs, $name);
        $interface = \App\Services\CatalogueGate::storageType($name, $specs);
        $provablyM2 = $interface === 'NVMe' || $interface === 'M.2 NVMe';

        $m = [];

        // --- PCB, edge connector, mounting point ---------------------------
        $m[] = Mesh::box($len, $wid, self::PCB_T, 'ssdPcb', [$len / 2, $wid / 2, self::PCB_T / 2]);
        $m[] = Mesh::box(self::EDGE_X, $wid, self::PCB_T, 'gold', [self::EDGE_X / 2, $wid / 2, self::PCB_T / 2]);
        $m[] = Mesh::box(4.0, 3.0, self::PCB_T, 'gold', [$len - 3.0, $wid / 2, self::PCB_T / 2]);
        $m[] = Mesh::cyl(2.6, min(1.2, max(0.6, $thk - self::PCB_T - 0.4)), 'z', 'ssdPcb', 10,
            [$len - 4.0, $wid / 2, self::PCB_T + 0.6]);

        // --- Controller + NAND --------------------------------------------
        // A bigger drive really does carry more NAND packages, which is the
        // main thing that distinguishes a 1TB stick from a 4TB stick by eye.
        $nandCount = $capacityGb >= 1000 ? 4 : 2;

        $m[] = Mesh::box(14.0, 14.0, 1.2, 'ssdChip', [self::EDGE_X + 12.0, $wid / 2, self::PCB_T + 0.6]);
        for ($i = 0; $i < $nandCount; $i++) {
            $x = self::CHIP_FIRST_X + $i * self::CHIP_PITCH;
            if ($x + 9.0 > $len - 8.0) {
                break;
            }
            $m[] = Mesh::box(9.0, 12.0, 1.2, 'ssdChip', [$x, $wid / 2, self::PCB_T + 0.6]);
        }
        // DRAM buffer on the large drives -" real from 2TB up.
        if ($capacityGb >= 2000 && $len - 22.0 > 30.0) {
            $m[] = Mesh::box(9.0, 10.0, 1.2, 'ssdChip', [$len - 20.0, $wid / 2, self::PCB_T + 0.6]);
        }

        // --- Heatsink + fins, only if the envelope has room for them -------
        $hsT = $thk - self::PCB_T;
        if ($hsT > 0.6) {
            $m[] = Mesh::box($len - 8.0, $wid - 2.0, $hsT, 'ssdHeatsink',
                [$len / 2 + 2.0, $wid / 2, self::PCB_T + $hsT / 2]);

            $finRun = $len - 12.0;
            $finCount = (int) max(4, floor($finRun / 6));
            $m[] = Mesh::stack(
                $finCount,
                $finRun / $finCount,
                [2.0, $wid - 5.0, max(0.4, $hsT - 0.8)],
                'x',
                'fin',
                [$len / 2 + 2.0, $wid / 2, self::PCB_T + $hsT / 2]
            );
        }

        return [
            'meshes' => $m,
            'meta' => [
                'form' => 'M.2 2280',
                'capacity_gb' => $capacityGb,
                'interface' => $interface === '' ? 'unknown' : $interface,
                // Announced, not hidden: an unknown interface means this could be
                // a 2.5" or 3.5" drive drawn at M.2 size. See the class docblock.
                'envelope_exceeds_package' => ! $provablyM2,
                'envelope' => ['x' => $len, 'y' => $wid, 'z' => $thk],
                'derivation' => 'envelope=PartDimensions; NAND count=specs.capacity; form=M.2 2280 constant',
            ],
        ];
    }

    protected static function capacityGb(array $specs, string $name): int
    {
        $raw = (string) ($specs['capacity'] ?? '');
        if (preg_match('/(\d+(?:\.\d+)?)\s*(tb|gb)/i', $raw, $m)) {
            $n = (float) $m[1];

            return (int) (mb_strtolower($m[2]) === 'tb' ? $n * 1000 : $n);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(tb|gb)/i', $name, $m)) {
            $n = (float) $m[1];

            return (int) (mb_strtolower($m[2]) === 'tb' ? $n * 1000 : $n);
        }

        return 0;
    }
}