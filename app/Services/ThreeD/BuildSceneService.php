<?php

namespace App\Services\ThreeD;

use App\Services\PartDimensions;

/**
 * Assembles the whole build into one declarative geometry spec, in REAL
 * MILLIMETRES, ready for the browser to turn into meshes.
 *
 * WHY THE SPEC IS BUILT SERVER-SIDE
 *
 * The brief's gate is "the GPU must not read as a box", and the test that proves
 * it is a PHP script that has to check real millimetres. So the geometry is
 * generated in PHP - one place, one set of numbers, hashable and testable - and
 * the browser only instantiates Three.js objects from it. Nothing about the
 * geometry is decided twice in two languages.
 *
 * COORDINATE CONVENTION (unchanged from the existing viewport, so the camera,
 * the glass panel and the cable routes all still line up):
 *   +X = case width, glass panel at -X, motherboard tray at +X
 *   +Y = up
 *   +Z = front-to-back, rear I/O at -Z, front intake at +Z
 *
 * Each part carries an explicit BASIS - three column vectors giving the case-space
 * direction of its local X, Y and Z. That is exact, and it sidesteps the
 * Euler-conversion bugs that a chain of 90-degree rotations invites.
 *
 * INSTALL ORIENTATION, stated because it was the single most contested point:
 * a GPU in a PCIe slot has its LENGTH along the board, its HEIGHT up the board
 * and its THICKNESS across it. The slot connector stands the card off the board,
 * so the card's backplate sits against the motherboard and its shroud and axial
 * fans face the case glass. That is why GPU fans are visible through a side
 * panel in every real build photo, and it is why the fan face is on the -X side
 * here rather than rotated for effect.
 */
final class BuildSceneService
{
    // Bumped to 4 when the AIO/ram placement bugs were fixed.
    //
    // meshSpec() keys its Cache::remember() on 'pctg3d:v'.VERSION . ':' . sha1(...),
    // so this is also how a geometry fix reaches the browser. Left at 3, the
    // corrected scene sat behind a 1-hour TTL and the render kept showing hour-old
    // geometry - pixel-identical screenshots across every code change, which is
    // exactly the sort of thing that makes a visual test look broken rather than
    // stale. Any change to the geometry MUST bump this.
    public const VERSION = 4;

    /** Categories a build can hold, in assembly order. */
    public const CATEGORIES = ['case', 'motherboard', 'cpu', 'cooler', 'ram', 'gpu', 'storage', 'psu'];

    private const SOCKET_LIFT = 8.0;   // CPU stands off the board's socket
    private const TRAY_GAP = 22.0;     // tray face to board surface
    private const FOOT_H = 20.0;

    public function __construct(private readonly PartDimensions $dimensions)
    {
    }

    /**
     * @param  array<string, array{id: int, name: string, specs: array}>  $selection
     * @return array<string, mixed>
     */
    public function forSelection(array $selection): array
    {
        $caseDims = $this->dimsFor($selection, 'case') ?? ['x' => 230, 'y' => 460, 'z' => 460,
            'glass' => 'side', 'max_gpu' => 400, 'max_cooler' => 170, 'rad_top' => 360, 'rad_front' => 360,
            'form' => 'mid-tower'];

        $cw = (float) $caseDims['x'];
        $ch = (float) $caseDims['y'];
        $cd = (float) $caseDims['z'];

        // Put the case's geometric centre on the world origin so the existing
        // camera framing still works untouched.
        $yLift = -($ch / 2 + self::FOOT_H);

        $caseGen = new CaseGeometry();
        $caseAnchors = $caseGen->anchors($caseDims, [], '', 'case');
        $boardOrigin = $caseAnchors['board_origin'];

        // Motherboard basis: local X (board length, rear -> front) -> case +Z,
        // local Y (board bottom -> top) -> case +Y, local Z (board thickness,
        // components face) -> case -X, i.e. toward the glass.
        $boardBasis = [[0.0, 0.0, 1.0], [0.0, 1.0, 0.0], [-1.0, 0.0, 0.0]];

        $parts = [];

        // --- CASE + its fans ------------------------------------------------
        $caseGeom = $caseGen->generate($caseDims, [], '', 'case');
        $parts[] = $this->part('case', 0, [0.0, $yLift, 0.0], $this->identity(), $caseGeom);

        $fans = (new CaseFans())->generate($caseDims, [], '', 'fan');
        $parts[] = $this->part('fans', 0, [0.0, $yLift, 0.0], $this->identity(), $fans);

        // --- MOTHERBOARD ----------------------------------------------------
        $mbDims = $this->dimsFor($selection, 'motherboard') ?? ['x' => 305, 'y' => 25, 'z' => 244];
        $mbGen = new MotherboardGeometry();
        $mbGeom = $mbGen->generate($mbDims, $this->specsFor($selection, 'motherboard'),
            $this->nameFor($selection, 'motherboard'), 'motherboard');
        $mbAnchors = $mbGen->anchors($mbDims, $this->specsFor($selection, 'motherboard'),
            $this->nameFor($selection, 'motherboard'), 'motherboard');

        $mbOrigin = [$boardOrigin[0], $boardOrigin[1] + $yLift, $boardOrigin[2]];
        $parts[] = $this->part('motherboard', $this->idFor($selection, 'motherboard'),
            $mbOrigin, $boardBasis, $mbGeom);

        // Board-local anchor -> case space, reused by everything that hangs off
        // the board. Derived, not re-derived: the board owns its own layout.
        $onBoard = function (array $local) use ($mbOrigin, $boardBasis): array {
            return $this->applyBasis($local, $mbOrigin, $boardBasis);
        };

        // --- CPU --------------------------------------------------------------
        $cpuDims = $this->dimsFor($selection, 'cpu') ?? ['x' => 40, 'y' => 90, 'z' => 9];
        $socketLocal = $mbAnchors['socket'] ?? [122.0, 161.0, 1.6];
        $socketCase = $onBoard([$socketLocal[0] - $cpuDims['x'] / 2, $socketLocal[1] - min(40.0, $cpuDims['x']) / 2, $socketLocal[2]]);

        if ($this->has($selection, 'cpu')) {
            $cpuGeom = (new CpuGeometry())->generate($cpuDims, $this->specsFor($selection, 'cpu'),
                $this->nameFor($selection, 'cpu'), 'cpu');
            $parts[] = $this->part('cpu', $this->idFor($selection, 'cpu'), $socketCase, $boardBasis, $cpuGeom);
        }

        // The CPU's top face in case space: where a cooler's contact plate lands.
        $cpuTop = [
            $socketCase[0] - (float) $cpuDims['z'],
            $socketCase[1],
            $socketCase[2],
        ];

        // --- COOLER ------------------------------------------------------------
        $coolerGeom = null;
        $coolerDims = $this->dimsFor($selection, 'cooler');
        $airBasis = [[0.0, -1.0, 0.0], [0.0, 0.0, -1.0], [1.0, 0.0, 0.0]];
        if ($coolerDims) {
            $coolerGen = new CoolerGeometry();
            $context = [
                'case' => $caseDims,
                'roof_y' => $ch + self::FOOT_H + $yLift,
                'socket_case' => $cpuTop,
            ];
            $coolerGeom = $coolerGen->generate($coolerDims, $this->specsFor($selection, 'cooler'),
                $this->nameFor($selection, 'cooler'), 'cooler', $context);

              foreach ($coolerGeom['groups'] ?? [] as $group) {
                  $t = $group['t'];
                  // AIO groups are marked 'space' => 'case': CoolerGeometry has
                  // already placed them in case space, using a roof_y that INCLUDES
                  // $yLift and a socket that came from $cpuTop, which is also
                  // already lifted. Adding $yLift again pushed the whole cooler
                  // 250mm too high - measured at 180mm outside the case wall.
                  //
                  // Air-tower meshes are in the cooler's OWN local frame and still
                  // need the lift applied by the caller's basis, which is why only
                  // the lifted case-space groups were wrong.
                  $inCaseSpace = ($group['space'] ?? 'case') === 'case';
                  $origin = $inCaseSpace
                      ? [$t[0], $t[1], $t[2]]
                      : [$t[0], $t[1] + $yLift, $t[2]];
                  $parts[] = $this->part('cooler:' . $group['key'], $this->idFor($selection, 'cooler'),
                      $origin, $this->identity(),
                      ['meshes' => $group['meshes'], 'meta' => ['group' => $group['key']]],
                      $group['envelope']);
              }

              // An AIO returns empty 'meshes' and fills 'groups' instead. Drawing the
              // air tower as well put a 125mm-tall tower with heatpipes and fins
              // alongside a 360mm radiator - a cooler that is neither - and the
              // tower's origin, derived from $cpuTop through $airBasis, landed
              // 180mm outside the case wall. Gate the air branch on the geometry
              // actually being an air cooler, not merely on meshes being present.
              if (! empty($coolerGeom['meshes']) && ($coolerDims['type'] ?? 'air') !== 'aio') {
                  // Air tower: stand it up on the CPU. The contact plate sits on the
                  // IHS and the +Y height axis points out of the board toward the
                  // glass, which is the direction `max_cpu_cooler_height` measures.
                  $towerW = (float) ($coolerDims['x'] ?? 125);
                  $towerD = (float) ($coolerDims['z'] ?? 135);
                  $t = $this->applyBasis([$towerW / 2, 0.0, $towerD / 2], $cpuTop, $airBasis);
                  $parts[] = $this->part('cooler', $this->idFor($selection, 'cooler'),
                      [$t[0], $t[1] + $yLift, $t[2]], $airBasis, $coolerGeom);
            }
        }

        // --- RAM ---------------------------------------------------------------
        $ramDims = $this->dimsFor($selection, 'ram') ?? ['x' => 133, 'y' => 32, 'z' => 7];
        if ($this->has($selection, 'ram')) {
            $ramGen = new RamGeometry();
            $ramGeom = $ramGen->generate($ramDims, $this->specsFor($selection, 'ram'),
                $this->nameFor($selection, 'ram'), 'ram');
            // A DIMM stands in its slot: length up the board, face toward the
            // glass. Two sticks, because that is how they are sold and fitted.
            $dimmSlots = (int) (($mbGeom['meta']['dimm_slots'] ?? 4));
            $count = min(4, max(2, $dimmSlots));
              $ramBasis = [[0.0, 0.0, 1.0], [1.0, 0.0, 0.0], [0.0, 1.0, 0.0]];
              for ($i = 0; $i < $count; $i++) {
                  $base = $mbAnchors['dimm'] ?? [160.0, 80.0, 4.6];
                  // Slot pitch goes along the board's LENGTH (local X), which is
                  // how DIMM slots actually sit: side by side across a 305mm edge.
                  // The old +$i * 9.5 was on local Z, the board's width, walking the
                  // sticks out through the case wall instead of along the board.
                  $local = [$base[0] + $i * 8.0, $base[1], $base[2]];

                  // $mbAnchors['dimm'] is a BOARD-LOCAL anchor, so the part origin
                  // must be rotated into case space by $onBoard(). It then carries
                  // $ramBasis to stand the stick up on the board.
                  //
                  // These are two different jobs and must both happen: rotating the
                  // anchor AND orienting the stick. Dropping $ramBasis leaves a
                  // 133mm DIMM lying flat (116mm outside the case); keeping the
                  // pre-rotated origin AND the basis rotates twice and does the same.
                  $t = $onBoard($local);
                  $parts[] = $this->part('ram', $this->idFor($selection, 'ram'),
                      [$t[0], $t[1] + $yLift, $t[2]], $ramBasis, $ramGeom,
                      ['x' => $ramDims['y'], 'y' => $ramDims['z'], 'z' => $ramDims['x']]);
            }
        }

        // --- GPU ----------------------------------------------------------------
        $gpuDims = $this->dimsFor($selection, 'gpu');
        $gpuPowerCase = null;
        if ($gpuDims) {
            $gpuGen = new GpuGeometry();
            $gpuGeom = $gpuGen->generate($gpuDims, $this->specsFor($selection, 'gpu'),
                $this->nameFor($selection, 'gpu'), 'gpu');
            $pcie = $mbAnchors['pcie'] ?? [190.0, 90.0, 8.6];
            // The card's own local frame matches the board's: length along the
            // board, height up the board, thickness out of the board.
            $t = $onBoard($pcie);
            $parts[] = $this->part('gpu', $this->idFor($selection, 'gpu'),
                [$t[0], $t[1] + $yLift, $t[2]], $boardBasis, $gpuGeom);

            $anchors = $gpuGen->anchors($gpuDims, $this->specsFor($selection, 'gpu'),
                $this->nameFor($selection, 'gpu'), 'gpu');
            $gpuPowerCase = $onBoard($anchors['power_in'] ?? [$gpuDims['x'] - 60, $gpuDims['y'], 30]);
        }

        // --- STORAGE -------------------------------------------------------------
        $storageDims = $this->dimsFor($selection, 'storage');
        $storageCase = null;
        if ($storageDims) {
            $storageGen = new StorageGeometry();
            $storageGeom = $storageGen->generate($storageDims, $this->specsFor($selection, 'storage'),
                $this->nameFor($selection, 'storage'), 'storage');
            // An M.2 stick lies flat on the board, so its frame matches the
            // board's exactly.
            $m2 = $mbAnchors['m2_1'] ?? [$mbDims['x'] * 0.42, $mbDims['z'] * 0.33, 1.6];
            $t = $onBoard([$m2[0] - $storageDims['x'] / 2, $m2[1] - $storageDims['y'] / 2, $m2[2]]);
            $parts[] = $this->part('storage', $this->idFor($selection, 'storage'),
                [$t[0], $t[1] + $yLift, $t[2]], $boardBasis, $storageGeom);
            $storageCase = $t;
        }

        // --- PSU -------------------------------------------------------------------
        $psuDims = $this->dimsFor($selection, 'psu');
        $psuExitCase = null;
        if ($psuDims) {
            $psuGen = new PsuGeometry();
            $psuGeom = $psuGen->generate($psuDims, $this->specsFor($selection, 'psu'),
                $this->nameFor($selection, 'psu'), 'psu');
            $psuCentre = $caseAnchors['psu_centre'] ?? [0.0, 65.0, -36.0];
            // In the basement, fan down to the floor, modular face forward.
            $t = [$psuCentre[0] - $psuDims['x'] / 2, $psuCentre[1] - $psuDims['y'] / 2, $psuCentre[2] + $psuDims['z'] / 2];
            $parts[] = $this->part('psu', $this->idFor($selection, 'psu'),
                [$t[0], $t[1] + $yLift, $t[2]], $this->identity(), $psuGeom);

            $psuAnchors = $psuGen->anchors($psuDims, $this->specsFor($selection, 'psu'),
                $this->nameFor($selection, 'psu'), 'psu');
            $a = $psuAnchors['cable_exit'];
            $psuExitCase = [$t[0] + $a[0], $t[1] + $a[1] + $yLift, $t[2] + $a[2]];
        }

        // --- CABLES ------------------------------------------------------------------
        $cables = $this->cables($psuExitCase, $gpuPowerCase, $storageCase, $onBoard($mbAnchors['atx24'] ?? [$mbDims['x'] - 9, 134, 8]), $yLift);
        if ($cables) {
            $parts[] = [
                'category' => 'cables',
                'id' => 0,
                't' => [0.0, 0.0, 0.0],
                'basis' => $this->identity(),
                'meshes' => $cables,
                'meta' => ['group' => 'cables'],
            ];
        }

        return [
            'v' => self::VERSION,
            'unit' => 'mm',
            'case' => ['x' => $cw, 'y' => $ch, 'z' => $cd],
            'parts' => $parts,
            'stats' => [
                'parts' => count($parts),
                'primitives' => array_sum(array_map(fn ($p) => count($p['meshes']), $parts)),
            ],
        ];
    }

    /**
     * The three runs every real build has: 24-pin ATX to the board, PCIe power
     * to the card, SATA to storage. Routed from resolved anchors, so they follow
     * the parts instead of being pinned to hard-coded offsets.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cables(?array $psuExit, ?array $gpuPower, ?array $storage, array $atx24, float $yLift): array
    {
        if (! $psuExit) {
            return [];
        }

        $m = [];
        $p = [$psuExit[0], $psuExit[1] - 6.0, $psuExit[2] - 14.0];

        if ($gpuPower) {
            $g = [$gpuPower[0], $gpuPower[1], $gpuPower[2]];
            $m[] = Mesh::tube(3.2, [
                $p,
                [($p[0] + $g[0]) / 2, $p[1] - 12.0, ($p[2] + $g[2]) / 2],
                [$g[0] - 26.0, ($p[1] + $g[1]) / 2, ($g[2] + $p[2]) / 2 - 12.0],
                $g,
            ], 'cable');
        }

        if ($atx24) {
            $a = [$atx24[0], $atx24[1], $atx24[2]];
            $m[] = Mesh::tube(3.6, [
                $p,
                [$p[0] + 20.0, $p[1] - 6.0, ($p[2] + $a[2]) / 2],
                [($p[0] + $a[0]) / 2 + 16.0, ($p[1] + $a[1]) / 2, ($a[2] + $p[2]) / 2 + 20.0],
                $a,
            ], 'cable');
        }

        if ($storage) {
            $s = [$storage[0], $storage[1] - 8.0, $storage[2]];
            $m[] = Mesh::tube(2.2, [
                $p,
                [$p[0] - 24.0, $p[1] - 4.0, ($p[2] + $s[2]) / 2],
                [($p[0] + $s[0]) / 2, ($p[1] + $s[1]) / 2 - 10.0, ($s[2] + $p[2]) / 2 + 16.0],
                $s,
            ], 'cableSata');
        }

        return array_map(function (array $mesh) use ($yLift): array {
            if (isset($mesh['pts'])) {
                $mesh['pts'] = array_map(fn (array $pt) => [
                    $pt[0], $pt[1] + $yLift, $pt[2],
                ], $mesh['pts']);
            }

            return $mesh;
        }, $m);
    }

    /**
     * @return array<string, mixed>
     */
    private function part(string $category, int $id, array $t, array $basis, array $geometry, ?array $envelope = null): array
    {
        $part = [
            'category' => $category,
            'id' => $id,
            't' => $t,
            'basis' => $basis,
            'meshes' => $geometry['meshes'] ?? [],
            'meta' => $geometry['meta'] ?? [],
        ];
        if ($envelope !== null) {
            $part['envelope'] = $envelope;
        }

        return $part;
    }

    /** @return array<int, array{0: float, 1: float, 2: float}> */
    private function identity(): array
    {
        return [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]];
    }

    /**
     * Transform a local point into the part's parent frame: origin + the basis
     * columns applied to the local vector. Exact, and no Euler conversion.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function applyBasis(array $local, array $origin, array $basis): array
    {
        $x = $local[0] * $basis[0][0] + $local[1] * $basis[1][0] + $local[2] * $basis[2][0];
        $y = $local[0] * $basis[0][1] + $local[1] * $basis[1][1] + $local[2] * $basis[2][1];
        $z = $local[0] * $basis[0][2] + $local[1] * $basis[1][2] + $local[2] * $basis[2][2];

        return [
            round($origin[0] + $x, 2),
            round($origin[1] + $y, 2),
            round($origin[2] + $z, 2),
        ];
    }

    /** @return array<string, mixed>|null */
    private function dimsFor(array $selection, string $category): ?array
    {
        $p = $selection[$category] ?? null;
        if (! $p) {
            return null;
        }
        if (! empty($p['dims']) && is_array($p['dims'])) {
            return $p['dims'];
        }

        return $this->dimensions->resolve($category, $p['name'] ?? '', $p['specs'] ?? []);
    }

    private function specsFor(array $selection, string $category): array
    {
        return (array) ($selection[$category]['specs'] ?? []);
    }

    private function nameFor(array $selection, string $category): string
    {
        return (string) ($selection[$category]['name'] ?? '');
    }

    private function idFor(array $selection, string $category): int
    {
        return (int) ($selection[$category]['id'] ?? 0);
    }

    private function has(array $selection, string $category): bool
    {
        return ! empty($selection[$category]['id']);
    }
}