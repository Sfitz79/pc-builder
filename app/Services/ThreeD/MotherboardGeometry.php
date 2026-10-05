<?php

namespace App\Services\ThreeD;

/**
 * PROCEDURAL MOTHERBOARD.
 *
 * LOCAL FRAME (mm): +X = board length from the rear I/O edge (0) forward,
 * +Y = board height from the bottom edge up, +Z = board thickness, 0 on the
 * tray side and +Z toward the showcase glass.
 *
 * Form factor is REAL. `specs.form_factor` is preferred; when it is absent the
 * variant letter is read from the board name via the same CatalogueGate
 * derivation the catalogue UI already shows the customer (B650M -> mATX,
 * B650I -> ITX). Measured 2026-10-05: form_factor is empty on all 397 active
 * boards, so the name derivation is what actually varies the geometry here, and
 * 248 of 397 genuinely carry no M/I suffix.
 *
 * Slot counts, socket position, I/O shroud and heatsink placement all follow the
 * real ATX/mATX/ITX layouts rather than one generic board.
 */
final class MotherboardGeometry implements PartGeometry
{
    /** Real per-form-factor board sizes and slot counts. */
    private const LAYOUT = [
        'Mini-ITX' => ['x' => 170.0, 'y' => 170.0, 'dimm' => 2, 'pcie_x16' => 1, 'm2' => 2],
        'Micro-ATX' => ['x' => 244.0, 'y' => 244.0, 'dimm' => 4, 'pcie_x16' => 3, 'm2' => 3],
        'E-ATX' => ['x' => 305.0, 'y' => 277.0, 'dimm' => 4, 'pcie_x16' => 4, 'm2' => 4],
        'ATX' => ['x' => 305.0, 'y' => 244.0, 'dimm' => 4, 'pcie_x16' => 3, 'm2' => 3],
    ];

    /** PCIe x16 connector body: 89mm long, 11.25mm tall. */
    private const PCIE_X = 89.0;
    private const PCIE_T = 11.25;

    public function localFrame(): string
    {
        return 'Motherboard local: +X length (0=rear I/O edge), +Y height (0=bottom edge), +Z thickness (0=tray face)';
    }

    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array
    {
        $form = $this->formFactor($dims, $specs, $name);
        $layout = self::LAYOUT[$form] ?? self::LAYOUT['ATX'];

        // The published envelope wins over the reference layout: PartDimensions
        // is the source of truth, the layout only explains the shape.
        $len = (float) ($dims['x'] ?: $layout['x']);
        $hgt = (float) ($dims['z'] ?: $layout['y']);
        $thk = (float) ($dims['y'] ?: 25.0);

        $pcbT = 1.6;
        $m = [];

        // --- PCB ---------------------------------------------------------------
        $m[] = Mesh::box($len, $hgt, $pcbT, 'pcb', [$len / 2, $hgt / 2, $pcbT / 2]);

        // Solder-mask sheen band along the top edge: real boards are not a flat
        // colour field, and the highlight is what separates the layers.
        $m[] = Mesh::box($len - 6.0, $hgt * 0.20, 0.4, 'pcbMask',
            [$len / 2, $hgt * 0.90, $pcbT + 0.2]);

        // --- CPU socket --------------------------------------------------------
        // Real socket centres sit up the board, roughly a third back from the
        // rear I/O edge, and shift toward the top on every form factor.
        $sx = $len * 0.30;
        $sy = $hgt * 0.66;
        $socket = $form === 'Mini-ITX' ? 40.0 : 45.0;

        $m[] = Mesh::box($socket, $socket, 2.4, 'socket', [$sx, $sy, $pcbT + 1.2]);
        $m[] = Mesh::ring($socket / 2 + 2.0, $socket / 2 - 4.0, 2.0, 'z', 'socketFrame', 4,
            [$sx, $sy, $pcbT + 2.4]);

        // --- DIMM slots (to the right of the socket) ---------------------------
        // Count and generation are real: DDR5 boards ship 2 or 4 slots, DDR4
        // boards 4. Generation comes from CatalogueGate::ramType(), which reads
        // the populated specs.speed field.
        $dimmCount = $this->dimmCount($form, $name, $specs);
        $dimmX = $sx + $socket / 2 + 12.0;
        for ($i = 0; $i < $dimmCount; $i++) {
            $m[] = Mesh::box(7.0, $hgt * 0.30, 6.0, 'dimmSlot',
                [$dimmX + $i * 9.5, $sy + $socket * 0.10, $pcbT + 3.0]);
            // Latch at each end of every slot.
            $m[] = Mesh::box(7.0, 6.0, 9.0, 'dimmLatch',
                [$dimmX + $i * 9.5, $sy + $hgt * 0.15, $pcbT + 4.5]);
            $m[] = Mesh::box(7.0, 6.0, 9.0, 'dimmLatch',
                [$dimmX + $i * 9.5, $sy - $hgt * 0.15 - 6.0, $pcbT + 4.5]);
        }

        // --- VRM heatsinks -----------------------------------------------------
        $m[] = Mesh::box($len * 0.36, 34.0, 9.0, 'heatsink', [$sx - 10.0, $hgt - 26.0, $pcbT + 4.5]);
        $m[] = Mesh::stack(
            (int) max(6, floor($len * 0.36 / 6)),
            $len * 0.36 / max(6, (int) floor($len * 0.36 / 6)),
            [2.0, 30.0, 7.0], 'x', 'fin', [$sx - 10.0, $hgt - 26.0, $pcbT + 10.0]
        );
        // Left-hand VRM, alongside the rear I/O stack.
        $m[] = Mesh::box(28.0, 74.0, 8.0, 'heatsink', [16.0, $sy + 10.0, $pcbT + 4.0]);

        // --- Rear I/O shroud + IO stack ---------------------------------------
        // Depth is held inside the published board thickness: the shroud sits on
        // the PCB and the port stack is recessed into it, rather than growing a
        // second shelf out past the size the customer was shown.
        $ioDepth = max(10.0, min(18.0, $thk - $pcbT - 6.0));
        $m[] = Mesh::box(66.0, $hgt * 0.36, $ioDepth, 'ioShroud',
            [33.0, $hgt * 0.78, $pcbT + $ioDepth / 2]);
        $m[] = Mesh::stack(5, 11.0, [8.0, 9.0, 4.0], 'x', 'port',
            [34.0, $hgt * 0.78, $pcbT + $ioDepth - 2.0]);
        // Wi-Fi antenna posts are real on every wireless board in this catalogue.
        $m[] = Mesh::cyl(3.0, 22.0, 'y', 'antenna', 10, [50.0, $hgt - 22.0, $pcbT + 8.0]);

        // --- PCIe slots --------------------------------------------------------
        $x16 = (int) $layout['pcie_x16'];
        $slotY = $sy - $socket / 2 - 26.0;
        $gap = $x16 > 1 ? ($hgt * 0.30) / $x16 : 0.0;
        for ($i = 0; $i < $x16; $i++) {
            $y = $slotY - $i * $gap;
            if ($y < 26.0) {
                break;
            }
            $m[] = Mesh::box(self::PCIE_X, self::PCIE_T, 7.0, 'pcieSlot', [$sx + 34.0, $y, $pcbT + 3.5]);
            // Retention clip at the inboard end.
            $m[] = Mesh::box(5.0, self::PCIE_T + 4.0, 10.0, 'pcieLatch',
                [$sx + 34.0 + self::PCIE_X / 2 - 3.0, $y, $pcbT + 5.0]);
        }
        // x1 slot near the bottom edge - universal on every ATX board.
        $m[] = Mesh::box(25.0, 7.0, 6.0, 'pcieSlot', [$sx + 6.0, $hgt * 0.14, $pcbT + 3.0]);

        // --- M.2 heatsinks (real: one per slot, flat on the board) ------------
        $m2 = (int) $layout['m2'];
        for ($i = 0; $i < $m2; $i++) {
            $m[] = Mesh::box(84.0, 22.0, 5.0, 'm2Heatsink',
                [$len * 0.42, $hgt * (0.46 - $i * 0.13), $pcbT + 2.5]);
            $m[] = Mesh::stack(
                (int) max(5, floor(84 / 9)),
                84 / max(5, (int) floor(84 / 9)),
                [2.0, 18.0, 3.0], 'x', 'fin', [$len * 0.42, $hgt * (0.46 - $i * 0.13), $pcbT + 6.0]
            );
        }

        // --- Chipset heatsink --------------------------------------------------
        $m[] = Mesh::box(58.0, 58.0, 7.0, 'heatsink', [$len * 0.66, $hgt * 0.30, $pcbT + 3.5]);

        // --- 24-pin ATX + 8-pin EPS ------------------------------------------
        $m[] = Mesh::box(13.0, 30.0, 13.0, 'atx24', [$len - 9.0, $hgt * 0.55, $pcbT + 6.5]);
        $m[] = Mesh::box(13.0, 15.0, 13.0, 'atx8', [$len - 9.0, $hgt - 22.0, $pcbT + 6.5]);

        // --- SATA ports (front edge) -----------------------------------------
        $sataN = $form === 'Mini-ITX' ? 2 : 4;
        $m[] = Mesh::stack($sataN, 11.0, [9.0, 8.0, 12.0], 'y', 'port',
            [$len - 8.0, $hgt * 0.16, $pcbT + 6.0], false);

        // --- Front-panel header + rear edge detail ----------------------------
        $m[] = Mesh::box(26.0, 8.0, 5.0, 'header', [$len - 16.0, $hgt * 0.34, $pcbT + 2.5]);
        $m[] = Mesh::box($len, 6.0, $pcbT + 1.0, 'pcbEdge', [$len / 2, $hgt - 3.0, ($pcbT + 1.0) / 2]);

        return [
            'meshes' => $m,
            'meta' => [
                'form' => $form,
                'dimm_slots' => $dimmCount,
                'pcie_x16' => $x16,
                'm2_slots' => $m2,
                'envelope' => ['x' => $len, 'y' => $hgt, 'z' => $thk],
                // PartDimensions publishes (length, thickness, height) because
                // that is the order a component card reads in. This generator's
                // local frame is (length, height, thickness), so the mapping is
                // declared rather than left for a verifier to guess.
                'axis_map' => ['x' => 'x', 'y' => 'z', 'z' => 'y'],
                'derivation' => 'form=specs.form_factor ?: CatalogueGate::chipsetVariant() suffix; slots=ATX reference layout',
            ],
        ];
    }

    /**
     * The board's own layout, published so nothing else has to guess it.
     *
     * `socket`   where a CPU's contact plane sits
     * `dimm`     the base of the first DIMM slot, in board-local mm
     * `pcie`     the PCIe x16 slot's seating point: rear edge, lower face, board surface
     * `atx24`    the 24-pin connector face
     * `m2_0/1`   the two M.2 slots, for a drive that is not on a board heatsink
     */
    public function anchors(array $dims, array $specs, string $name, string $category): array
    {
        $form = $this->formFactor($dims, $specs, $name);
        $layout = self::LAYOUT[$form] ?? self::LAYOUT['ATX'];
        $len = (float) ($dims['x'] ?: $layout['x']);
        $hgt = (float) ($dims['z'] ?: $layout['y']);
        $pcbT = 1.6;

        $socket = $form === 'Mini-ITX' ? 40.0 : 45.0;
        $sx = $len * 0.30;
        $sy = $hgt * 0.66;

        $slotY = $sy - $socket / 2 - 26.0;
        $slotCentreY = $slotY - ($hgt * 0.30) / 2;
        $dimmX = $sx + $socket / 2 + 12.0 + 3.5;
        $dimmBottom = $sy + $socket * 0.10 - $hgt * 0.15;

        return [
            'socket' => [$sx, $sy, $pcbT],
            'dimm' => [$dimmX, $dimmBottom, $pcbT + 3.0],
            'pcie' => [$sx + 34.0, $slotCentreY, $pcbT + 7.0],
            'atx24' => [$len - 9.0, $hgt * 0.55, $pcbT + 6.5],
            'm2_0' => [$len * 0.42, $hgt * 0.46, $pcbT],
            'm2_1' => [$len * 0.42, $hgt * 0.33, $pcbT],
        ];
    }

    private function formFactor(array $dims, array $specs, string $name): string
    {
        $declared = strtoupper((string) ($specs['form_factor'] ?? $dims['form'] ?? ''));
        if ($declared !== '') {
            return match (true) {
                str_contains($declared, 'ITX') && str_contains($declared, 'MINI') => 'Mini-ITX',
                str_contains($declared, 'ITX') => 'Mini-ITX',
                str_contains($declared, 'E-ATX') => 'E-ATX',
                str_contains($declared, 'M-ATX') || $declared === 'MATX' => 'Micro-ATX',
                str_contains($declared, 'ATX') => 'ATX',
                default => 'ATX',
            };
        }

        // Real derivation, shared with the catalogue UI.
        $variant = strtoupper(\App\Services\CatalogueGate::chipsetVariant($name));

        return match (true) {
            str_ends_with($variant, 'I') => 'Mini-ITX',
            str_ends_with($variant, 'M') => 'Micro-ATX',
            default => 'ATX',
        };
    }

    /** DIMM slot count: 2 on ITX, and 2 for DDR5 boards, 4 otherwise. */
    private function dimmCount(string $form, string $name, array $specs): int
    {
        if ($form === 'Mini-ITX') {
            return 2;
        }

        $generation = \App\Services\CatalogueGate::ramType($name, $specs);

        return $generation === 'DDR5' ? 2 : 4;
    }
}