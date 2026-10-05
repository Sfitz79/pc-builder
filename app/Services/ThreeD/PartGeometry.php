<?php

namespace App\Services\ThreeD;

/**
 * Every component contract for the 3D viewport.
 *
 * The envelope (x/y/z, and whatever else PartDimensions publishes) is REAL and
 * comes from App\Services\PartDimensions. A generator may only add internal
 * detail - and it must always stay INSIDE that envelope. scripts/verify-3d-
 * geometry.php asserts this per part, because a generator that quietly grows
 * past the published dims is lying to the customer about the size of the part
 * they are being quoted.
 */
interface PartGeometry
{
    /**
     * @param  array<string, mixed>  $dims   PartDimensions::resolve() output. Real mm.
     * @param  array<string, mixed>  $specs  The component's real specs JSON.
     * @param  string  $name  The component name (a real spec source - never used for branding).
     * @param  array<string, mixed>  $context  Case + board anchors, for parts that mount
     *         onto something other than their own envelope (an AIO radiator sits on
     *         the case roof, its pump block on the CPU).
     */
    public function generate(array $dims, array $specs, string $name, string $category, array $context = []): array;

    /**
     * Named attachment points in this part's LOCAL frame.
     *
     * The generator owns its own layout, so it owns its own anchors: the scene
     * assembler asks where the PCIe slot is instead of re-deriving a second,
     * quietly divergent copy of the board layout. Everything is in local mm.
     *
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public function anchors(array $dims, array $specs, string $name, string $category): array;

    /** Local-space description, for the generator docblocks and the verifier. */
    public function localFrame(): string;
}