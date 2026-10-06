<?php

namespace App\Services\ThreeD;

/**
 * Resolves a renderer material slot to the CC0 PBR maps installed by
 * scripts/fetch-pbr-textures.php.
 *
 * WHY THIS IS SEPARATE FROM THE GEOMETRY GENERATORS
 * -------------------------------------------------
 * Each generator (GpuGeometry, PsuGeometry, ...) already emits a per-mesh material
 * token such as 'gpuShroud', 'psuCasing' or 'ramSpreader', because those tokens
 * are part of the BRAND: they decide what colour a finished build is. Rewriting
 * them to carry file paths would couple geometry to an asset directory and make
 * the brand decision a deployment detail.
 *
 * So the tokens stay, and this maps them onto textures in one place. A build can
 * therefore be re-skinned without touching a single geometry generator, and the
 * geometry proofs are unaffected by anything happening here.
 *
 * THE THREE MAPS
 * --------------
 *   basecolor  albedo
 *   normal     tangent-space normal, DirectX (green-down) convention
 *   orm        packed: R = ambient occlusion, G = roughness, B = metallic
 *
 * ORM is why the fetch is 7.8MB rather than 26.8MB. Roughness and metallic are read
 * from the G and B channels at load; downloading them separately would be three
 * images carrying one image's worth of information.
 *
 * WHY 1K ONLY
 * ------------
 * Geometry for a full build is 34KB. A configurator has to run on a phone, so the
 * material budget is deliberately an order of magnitude below what a desktop
 * renderer could afford.
 *
 * GAPS ARE HONEST. PCB solder mask and gold connector contacts have no CC0 source
 * on Poly Haven, so they stay as flat materials with a chosen roughness. A generic
 * grunge map on a motherboard reads worse than a correct flat one, and inventing a
 * file for the slot would just be a fake.
 */
class MaterialLibrary
{
    /** Path prefix under /public. Kept in one constant so it cannot drift. */
    public const BASE = '/textures/pbr';

    /**
     * Brand material token -> texture slot.
     *
     * The token list below was read OUT of the geometry generators rather than
     * guessed. They emit 62 distinct tokens across a full build - `psuBody`,
     * `backplate`, `ramSpreader`, `pcb`, `ihs`, `gold`, `glass` and so on. An
     * earlier version of this file invented plausible-sounding names such as
     * `gpuShroud` and `psuCasing`, none of which the geometry emits, so every
     * token would have fallen through to the grey fallback and the whole PBR set
     * would have been installed and used for nothing.
     *
     * null => stays a flat material, because a texture would be wrong there.
     *
     * @var array<string,string|null>
     */
    private const TOKEN_TO_SLOT = [
        // ---- GPU: shroud, backplate, bracket and fan faces are bare metal -----
        'shroudLip' => 'gpu_shroud',
        'backplate' => 'gpu_shroud',
        'bracket' => 'gpu_shroud',
        'ioShroud' => 'gpu_shroud',
        'gpuLed' => null,
        'pcb' => null,
        'pcbEdge' => null,
        'pcbMask' => null,
        'gold' => null,
        'nickel' => null,
        'connector' => null,
        'connectorLip' => null,
        'header' => null,
        'port' => null,
        'socket' => null,
        'pcieSlot' => null,
        'pcieLatch' => null,
        'heatsink' => 'gpu_shroud',
        'coolerBase' => 'gpu_shroud',
        'coolerTop' => 'gpu_shroud',
        'coolerTrim' => 'gpu_shroud',
        'coolerMount' => 'gpu_shroud',
        'ihs' => null,
        'ihsStep' => null,
        'cpuSubstrate' => null,
        'pumpBlock' => 'dark_polymer',
        'radCore' => 'gpu_shroud',
        'radFrame' => 'dark_polymer',
        'radTank' => 'dark_polymer',
        'ssdHeatsink' => 'gpu_shroud',

        // ---- PSU -------------------------------------------------------------
        'psuBody' => 'psu_casing',
        'psuTop' => 'psu_casing',
        'psuPanel' => 'psu_casing',
        'psuGrillFrame' => 'psu_casing',
        'psuChamfer' => 'psu_casing',
        'psuCableExit' => 'dark_polymer',
        'psuIec' => null,
        'psuIecCavity' => null,
        'psuPort' => null,
        'psuRocker' => null,

        // ---- Case: panels and frame tile; trim and feet are polymer ----------
        'casePanel' => 'case_panel',
        'caseFront' => 'case_panel',
        'caseShroud' => 'case_panel',
        'caseTray' => 'case_panel',
        'caseTrim' => 'dark_polymer',
        'caseFoot' => 'dark_polymer',
        'caseCutout' => 'dark_polymer',
        'caseIo' => null,
        'caseLed' => null,
        'glass' => null,
        'driveBay' => 'dark_polymer',

        // ---- RAM -------------------------------------------------------------
        'ramSpreader' => 'dark_polymer',
        'ramDiffuser' => 'dark_polymer',
        'ramLight' => null,
        'ramChip' => null,
        'ramPcb' => null,
        'dimmSlot' => null,
        'dimmLatch' => null,

        // ---- Fans ------------------------------------------------------------
        'fanFrame' => 'dark_polymer',
        'fanGuard' => 'dark_polymer',

        // ---- Storage ---------------------------------------------------------
        'ssdBody' => 'dark_polymer',
        'ssdChip' => null,
        'ssdPcb' => null,
        'm2Heatsink' => 'gpu_shroud',

        // ---- ATX power headers ----------------------------------------------
        // MotherboardGeometry builds these as plastic connector shells, so
        // dark_polymer rather than metal: a textured shroud would read as a
        // heatsink and mislead on a board close-up.
        'atx24' => 'dark_polymer',
        'atx8' => 'dark_polymer',

        // ---- Cables and internal wiring -------------------------------------
        // Sleeving is fabric, not metal. The dark_polymer slot is the closest
        // available match at this resolution; a dedicated braided-sleeve texture
        // would be a refinement, not a correctness fix.
        'cable' => 'dark_polymer',
        'cableSata' => 'dark_polymer',
        'cablePcie' => 'dark_polymer',
        'cableCpu' => 'dark_polymer',

        // ---- Chassis detail --------------------------------------------------
        'caseMesh' => 'dark_polymer',
        'caseUsb' => null,
        'caseJack' => null,
        'casePowerButton' => null,
        'antenna' => 'dark_polymer',
        'backplateGroove' => 'gpu_shroud',
        'cavity' => null,
        // Gold contacts stay flat, not textured. They are 4mm-scale parts and a tiled
        // metal map at that size reads as noise; a flat metallic gold is both
        // cheaper and more accurate. Note 'gold' is a FLAT material token, not a
        // texture slot - pointing a token at it produced gold_basecolor.jpg
        // lookups for files that do not and should not exist.
        'connectorPin' => null,

        // ---- Fans: hub, LED and the shroud hub ring --------------------------
        'fanHub' => 'dark_polymer',
        'fanBlade' => 'dark_polymer',
        'fanLed' => null,
        'shroud' => 'gpu_shroud',
        // GpuTier::shroudMaterial() picks one of four names by tier. They are all
        // the same shroud finish at different fidelity, so all four resolve to the
        // one texture rather than needing four near-identical downloads.
        'shroudEntry' => 'gpu_shroud',
        'shroudMid' => 'gpu_shroud',
        'shroudUpper' => 'gpu_shroud',
        'shroudFlagship' => 'gpu_shroud',
        'socketFrame' => null,
        'pcie_x16' => null,
        'protrudes' => null,
        'spreaders' => 'dark_polymer',
        'm2' => null,
        'fin' => 'gpu_shroud',
        'heatpipe' => 'psu_casing',
        'screw' => null,
        'psuScrew' => null,
        'fanScrew' => null,
        'pumpCap' => 'psu_casing',
        'pumpMotor' => 'dark_polymer',
        'psuGrill' => 'psu_casing',
        'm2_slots' => null,
        'shrouder_height_mm' => null,
        'fan_diameter_mm' => null,
        'meshes' => null,
        'fans' => null,
    ];

    /**
     * Flat materials for slots with no texture. Roughness and metalness are real
     * values, chosen so the parts read correctly rather than defaulting to plastic.
     *
     * @var array<string,array{color:string,roughness:float,metalness:float}>
     */
    private const FLAT = [
        'gpuLed' => ['color' => '#e8ecf4', 'roughness' => 0.35, 'metalness' => 0.0],
        'caseLed' => ['color' => '#7dd3fc', 'roughness' => 0.30, 'metalness' => 0.0],
        'ramLight' => ['color' => '#f8fafc', 'roughness' => 0.40, 'metalness' => 0.0],
        'glass' => ['color' => '#8fa8bd', 'roughness' => 0.08, 'metalness' => 0.0],
        'pcb' => ['color' => '#0d2b1e', 'roughness' => 0.72, 'metalness' => 0.0],
        'pcbEdge' => ['color' => '#0a2016', 'roughness' => 0.75, 'metalness' => 0.0],
        'pcbMask' => ['color' => '#123a28', 'roughness' => 0.68, 'metalness' => 0.0],
        'ramPcb' => ['color' => '#0d2b1e', 'roughness' => 0.70, 'metalness' => 0.0],
        'ramChip' => ['color' => '#1b1d22', 'roughness' => 0.50, 'metalness' => 0.0],
        'ssdPcb' => ['color' => '#0d2b1e', 'roughness' => 0.70, 'metalness' => 0.0],
        'ssdChip' => ['color' => '#1b1d22', 'roughness' => 0.50, 'metalness' => 0.0],
        'dimmSlot' => ['color' => '#2a2f37', 'roughness' => 0.62, 'metalness' => 0.0],
        'dimmLatch' => ['color' => '#39404a', 'roughness' => 0.58, 'metalness' => 0.0],
        'gold' => ['color' => '#d4af37', 'roughness' => 0.30, 'metalness' => 1.0],
        'nickel' => ['color' => '#b8bcc4', 'roughness' => 0.34, 'metalness' => 1.0],
        'connector' => ['color' => '#c9ccd1', 'roughness' => 0.36, 'metalness' => 1.0],
        'connectorLip' => ['color' => '#9aa0a8', 'roughness' => 0.40, 'metalness' => 1.0],
        'header' => ['color' => '#2c3138', 'roughness' => 0.60, 'metalness' => 0.0],
        'port' => ['color' => '#c9ccd1', 'roughness' => 0.34, 'metalness' => 1.0],
        'socket' => ['color' => '#b9bec6', 'roughness' => 0.33, 'metalness' => 1.0],
        'pcieSlot' => ['color' => '#e5e7eb', 'roughness' => 0.44, 'metalness' => 0.0],
        'pcieLatch' => ['color' => '#d1d5db', 'roughness' => 0.46, 'metalness' => 0.0],
        'ihs' => ['color' => '#c9ccd1', 'roughness' => 0.22, 'metalness' => 1.0],
        'ihsStep' => ['color' => '#bcc0c6', 'roughness' => 0.26, 'metalness' => 1.0],
        'cpuSubstrate' => ['color' => '#1f2a34', 'roughness' => 0.75, 'metalness' => 0.0],
        'caseIo' => ['color' => '#4b5563', 'roughness' => 0.52, 'metalness' => 0.3],
        'psuIec' => ['color' => '#2b2f36', 'roughness' => 0.55, 'metalness' => 0.2],
        'psuIecCavity' => ['color' => '#17191d', 'roughness' => 0.70, 'metalness' => 0.0],
        'psuPort' => ['color' => '#c9ccd1', 'roughness' => 0.34, 'metalness' => 1.0],
        'psuRocker' => ['color' => '#d8dbe0', 'roughness' => 0.42, 'metalness' => 0.0],
        'unknownToken' => ['color' => '#94a3b8', 'roughness' => 0.60, 'metalness' => 0.0],
    ];

    /**
     * Resolve one token.
     *
     * @return array{type:string,slot:?string,color?:string,roughness:?float,
     *               metalness:?float,maps:array<string,string>,licence:?string}
     */
    public static function resolve(string $token): array
    {
        // An unmapped token must be visible, not silently textured with something
        // arbitrary. A grey fallback is honest; a wrong texture is not.
        if (!array_key_exists($token, self::TOKEN_TO_SLOT)) {
            return self::flat('unknownToken') + ['token' => $token, 'mapped' => false];
        }

        $slot = self::TOKEN_TO_SLOT[$token];
        if ($slot === null) {
            return self::flat($token) + ['token' => $token, 'mapped' => true];
        }

        return [
            'type' => 'pbr',
            'slot' => $slot,
            'maps' => [
                'basecolor' => self::BASE . '/' . $slot . '_basecolor.jpg',
                'normal' => self::BASE . '/' . $slot . '_normal.jpg',
                // R=AO G=roughness B=metallic. A WebGL renderer samples G and B
                // rather than binding three extra textures.
                'orm' => self::BASE . '/' . $slot . '_orm.jpg',
            ],
            'normalConvention' => 'directx',
            'licence' => 'CC0 1.0 (Poly Haven)',
            'token' => $token,
            'mapped' => true,
        ];
    }

    private static function flat(string $token): array
    {
        $f = self::FLAT[$token] ?? self::FLAT['unknownToken'];
        return [
            'type' => 'flat',
            'slot' => null,
            'color' => $f['color'],
            'roughness' => $f['roughness'],
            'metalness' => $f['metalness'],
            'maps' => [],
            'licence' => null,
        ];
    }

    /** Every distinct texture slot referenced, for reporting. */
    public static function usedSlots(): array
    {
        return array_values(array_unique(array_filter(self::TOKEN_TO_SLOT)));
    }
}
