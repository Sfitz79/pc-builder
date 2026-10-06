<?php

namespace App\Services;

/**
 * Resolves physical dimensions (mm) for the 3D build viewport.
 *
 * EVERY RETURNED DIMENSION CARRIES ITS PROVENANCE
 * ----------------------------------------------
 * This class used to fall back to hardcoded numbers with no way to tell a
 * measurement from a guess:
 *
 *     $length = (int) ($specs['length'] ?? 300);
 *     $maxGpu = $specs['max_gpu_length'] ?? max(320, (int) $d - 80);
 *
 * Measured on PRODUCTION Neon (2026-10-06, 5626 dimension keys across 2708 active
 * components), via scripts/dimension-provenance.php:
 *
 *     catalogue-sourced           25 / 5626   ( 0.4%)
 *     FABRICATED fallback keys     0 / 4942   ( 0.0% sourced)
 *     STANDARD  fallback keys     25 /   684   ( 3.7% sourced)
 *
 * So in production EVERY GPU length, case clearance, cooler height, RAM height,
 * CPU height and drive dimension the viewport draws was invented. Worse, the
 * fabrication chained: a case with no recorded depth became 460mm, and
 * max_gpu_length was then derived from that invented depth, so the GPU's invented
 * 300mm length was compared against an invented clearance. verify-3d-assembly.php
 * then reported "every internal sits inside the case envelope" - a verdict
 * produced by one guess checking another, which cannot fail.
 *
 * Two kinds of fallback are distinguished, because conflating them overstates the
 * problem:
 *
 *   catalogue  the number is in the row. True for this exact part.
 *   standard   a published figure for the form factor. Mini-ITX IS 170x170mm; an
 *              ATX PSU IS 140x86x150mm. True for the class even when the row is
 *              silent, and legitimate to fall back to.
 *   assumed    a guess with no basis. 300mm for an unknown GPU is not "roughly
 *              right", it is a number that will be drawn to scale and believed.
 *
 * Nothing here is a customer promise. FitVerification reads the RAW parts array
 * and reports `unverified` when a dimension is absent, so it fails closed and is
 * unaffected by any of this. The risk is confined to the VIEWPORT, which is where
 * a customer looks and concludes the build is right.
 *
 * The rules for the new code:
 *   - an assumed dimension must be visible in the output as `assumed`
 *   - it must never be silently promoted to a measurement
 *   - anything gating on geometry must be able to refuse to certify a scene built
 *     on assumed numbers
 */
class PartDimensions
{
    public const CATALOGUE = 'catalogue';
    public const STANDARD = 'standard';
    public const ASSUMED = 'assumed';

    /**
     * Physical millimetre dims for a component. Keys: x (width), y (height),
     * z (depth). Returned as [x, y, z] box dims plus optional placement hints
     * used by the viewport (slot offset, thickness, radiator length...).
     *
     * Always includes a `provenance` map: field => [source, key, value, note].
     * Callers that gate on geometry MUST read it - see assertTrusted().
     */
    public function resolve(?string $category, ?string $name, array $specs = []): array
    {
        $slug = trim(mb_strtolower((string) $name));

        return match ($category) {
            'case'      => $this->caseDims($specs),
            'gpu'       => $this->gpuDims($specs),
            'cpu'       => $this->resolveFromSpecs($specs, 40, 90, 9, ['height' => 'cpu_height', 'width' => 'cpu_width'], $name),
            'motherboard' => $this->motherboardDims($specs),
            'ram'       => $this->resolveFromSpecs($specs, 133, 32, 7),
            'storage'   => $this->resolveFromSpecs($specs, 80, 22, 3),
            'psu'       => $this->psuDims($specs),
            'cooler'    => $this->coolerDims($specs, $slug),
            default     => $this->resolveFromSpecs($specs, 100, 100, 50),
        };
    }

    /**
     * Whether the part simply has no physical presence in the viewport
     * (e.g. software/licence-only rows) and should be skipped.
     */
    public function isPlaceholder(?string $category, array $specs = []): bool
    {
        return in_array($category, ['software', 'services', 'accessory', 'monitor'], true);
    }

    /**
     * Summarise a resolved-dims array for reporting or gating.
     *
     * @return array{sourced:int,standard:int,assumed:int,total:int,trusted:bool,fields:array<int,string>}
     */
    public static function summarise(array $dims): array
    {
        $prov = is_array($dims['provenance'] ?? null) ? $dims['provenance'] : [];

        $out = ['sourced' => 0, 'standard' => 0, 'assumed' => 0, 'total' => 0, 'trusted' => true, 'fields' => []];
        foreach ($prov as $field => $p) {
            $src = (string) ($p['source'] ?? self::ASSUMED);
            $out['total']++;
            $out[$src] = ($out[$src] ?? 0) + 1;
            if ($src === self::ASSUMED) {
                $out['trusted'] = false;
                $out['fields'][] = $field;
            }
        }

        return $out;
    }

    /**
     * Refuse to certify geometry built on assumed dimensions.
     *
     * @throws \RuntimeException when any dimension is assumed
     */
    public static function assertTrusted(array $dims, string $context = 'geometry'): void
    {
        $s = self::summarise($dims);
        if (! $s['trusted']) {
            throw new \RuntimeException(sprintf(
                'Refusing to certify %s: %d of %d dimensions are ASSUMED, not measured (%s). '
                . 'A comparison involving a guess is not a clearance check.',
                $context, $s['assumed'], $s['total'], implode(', ', $s['fields'])
            ));
        }
    }

    // --- internals ----------------------------------------------------------

    /**
     * Read the first present spec key, recording where the value came from.
     *
     * @param  array<string,string>  $prov  provenance accumulator, by reference
     */
    private function pick(
        array $specs,
        array $keys,
        $fallback,
        string $field,
        string $fallbackSource,
        array &$prov,
        string $note = ''
    ) {
        foreach ($keys as $k) {
            $v = $specs[$k] ?? null;
            if ($v !== null && $v !== '' && $v !== []) {
                $prov[$field] = [
                    'source' => self::CATALOGUE,
                    'key' => $k,
                    'value' => $v,
                ];

                return $v;
            }
        }

        $prov[$field] = [
            'source' => $fallbackSource,
            'key' => null,
            'value' => $fallback,
            'note' => $note ?: ('no catalogue value; using ' . $fallbackSource . ' fallback'),
        ];

        return $fallback;
    }

    protected function caseDims(array $specs): array
    {
        $prov = [];

        $h = (int) $this->pick($specs, ['height'], 460, 'height', self::ASSUMED, $prov,
            'no measured height; drawn as a generic mid-tower');
        $w = (int) $this->pick($specs, ['width'], 230, 'width', self::ASSUMED, $prov,
            'no measured width; drawn as a generic mid-tower');
        $d = (int) $this->pick($specs, ['depth'], 460, 'depth', self::ASSUMED, $prov,
            'no measured depth; drawn as a generic mid-tower');

        // Deriving a clearance from a possibly-assumed depth is chained fabrication,
        // and used to be invisible. It is now recorded as assumed explicitly, and the
        // note says so, because "max_gpu came from the depth" reads as evidence.
        $maxGpu = $this->pick($specs, ['max_gpu_length'], max(320, $d - 80), 'max_gpu', self::ASSUMED, $prov,
            'derived from depth, which is itself assumed - this is a guess, not a clearance');

        $maxCooler = $this->pick($specs, ['max_cpu_cooler_height', 'max_cooler_height'], 170, 'max_cooler', self::ASSUMED, $prov,
            'no measured cooler clearance');

        $radTop = $this->pick($specs, ['rad_top'], 360, 'rad_top', self::ASSUMED, $prov, 'assumed 360mm top radiator');
        $radFront = $this->pick($specs, ['rad_front'], 360, 'rad_front', self::ASSUMED, $prov, 'assumed 360mm front radiator');

        $form = $this->pick($specs, ['form_factor', 'supported_form_factors'], 'mid-tower', 'form', self::ASSUMED, $prov,
            'no recorded case form factor');

        $glass = $this->pick($specs, ['glass_panels'], 'side', 'glass', self::STANDARD, $prov,
            'glass position is a presentation default, not a measured value');

        return [
            'x' => $w, 'y' => $h, 'z' => $d,
            'glass' => $glass,
            'rad_top' => $radTop,
            'rad_front' => $radFront,
            'max_gpu' => $maxGpu,
            'max_cooler' => $maxCooler,
            'form' => $form,
            'provenance' => $prov,
        ];
    }

    protected function gpuDims(array $specs): array
    {
        $prov = [];

        $length = (int) $this->pick($specs, ['length', 'length_mm'], 300, 'length', self::ASSUMED, $prov,
            'no measured length; drawn as a 300mm card, which is NOT a measurement');
        $height = (int) $this->pick($specs, ['height', 'height_mm'], 120, 'height', self::ASSUMED, $prov,
            'no measured height; drawn as a 120mm card');
        $slots = (float) $this->pick($specs, ['thickness_slots'], 2.75, 'slots', self::ASSUMED, $prov,
            'no measured slot thickness; drawn as 2.75 slots (56mm)');

        return [
            'x' => $length, 'y' => $height, 'z' => $slots * 20.3,
            'slots' => $slots,
            'tflops' => isset($specs['tflops']) ? (float) $specs['tflops'] : null,
            'memory' => $specs['memory'] ?? null,
            'provenance' => $prov,
        ];
    }

    protected function motherboardDims(array $specs): array
    {
        $prov = [];

        $form = $this->pick($specs, ['form_factor'], 'ATX', 'form', self::STANDARD, $prov,
            'no recorded form factor; sized from the published ATX standard');

        // These ARE published standards, so a silent fallback is legitimate here and
        // the provenance records `standard`, not `assumed`.
        $std = match (strtoupper((string) $form)) {
            'MINI-ITX', 'MINI_ITX' => [170, 25, 170],
            'MICRO-ATX', 'MICRO_ATX', 'M-ATX' => [244, 25, 244],
            'E-ATX', 'EATX' => [305, 25, 277],
            default => [305, 25, 244],
        };

        $prov['x'] = ['source' => self::STANDARD, 'key' => 'form_factor', 'value' => $form,
            'note' => "board size derived from the published {$form} standard"];
        $prov['y'] = ['source' => self::STANDARD, 'key' => null, 'value' => 25, 'note' => 'PCB thickness standard'];
        $prov['z'] = ['source' => self::STANDARD, 'key' => 'form_factor', 'value' => $form,
            'note' => "board size derived from the published {$form} standard"];

        return ['x' => $std[0], 'y' => $std[1], 'z' => $std[2], 'form' => $form, 'provenance' => $prov];
    }

    protected function psuDims(array $specs): array
    {
        $prov = [];

        $form = $this->pick($specs, ['form', 'form_factor'], 'ATX', 'form', self::STANDARD, $prov,
            'no recorded PSU form; sized from the published ATX standard');

        $std = match (strtoupper((string) $form)) {
            'SFX' => ['x' => 100, 'y' => 63.5, 'z' => 125, 'form' => 'SFX'],
            default => ['x' => 140, 'y' => 86, 'z' => 150, 'form' => 'ATX'],
        };

        foreach (['x', 'y', 'z'] as $axis) {
            $prov[$axis] = ['source' => self::STANDARD, 'key' => 'form', 'value' => $form,
                'note' => "envelope from the published {$std['form']} PSU standard"];
        }

        return ['x' => $std['x'], 'y' => $std['y'], 'z' => $std['z'], 'form' => $std['form'], 'provenance' => $prov];
    }

    protected function coolerDims(array $specs, string $slug = ''): array
    {
        $prov = [];

        $isAio = isset($specs['type']) && preg_match('/aio|liquid/i', (string) $specs['type'])
            || str_contains($slug, 'liquid')
            || str_contains($slug, 'arctic liquid')
            || str_contains($slug, 'kraken')
            || str_contains($slug, 'aio');

        if ($isAio) {
            $radLen = (int) $this->pick($specs, ['radiator_length', 'rad_length'], 360, 'radiator_length', self::ASSUMED, $prov,
                'no measured radiator size; drawn as 360mm');

            // A fan count derived from a radiator size that may itself be assumed is
            // chained fabrication. Recorded as assumed, not as measured.
            $fans = (int) $this->pick($specs, ['fan_count'], match ($radLen) {
                120 => 1, 140 => 1, 240 => 2, 280 => 2, default => 3,
            }, 'fan_count', self::ASSUMED, $prov, 'derived from the radiator size, which is itself assumed');

            $prov['z'] = ['source' => self::STANDARD, 'key' => null, 'value' => 52, 'note' => 'standard radiator thickness'];

            return [
                'x' => $radLen, 'y' => $fans * 25, 'z' => 52,
                'type' => 'aio',
                'radiator_length' => $radLen,
                'fan_count' => $fans,
                'provenance' => $prov,
            ];
        }

        // Air tower
        $h = (int) $this->pick($specs, ['height', 'height_mm'], 160, 'height', self::ASSUMED, $prov,
            'no measured cooler height; drawn as 160mm');
        $prov['x'] = ['source' => self::ASSUMED, 'key' => null, 'value' => 125, 'note' => 'assumed 125mm tower width'];
        $prov['z'] = ['source' => self::ASSUMED, 'key' => null, 'value' => 135, 'note' => 'assumed 135mm tower depth'];

        return [
            'x' => 125, 'y' => $h, 'z' => 135,
            'type' => 'air',
            'provenance' => $prov,
        ];
    }

    protected function resolveFromSpecs(array $specs, int $dx, int $dy, int $dz, array $map = [], string $label = ''): array
    {
        $prov = [];

        $x = (int) $this->pick($specs, [$map['width'] ?? 'width'], $dx, 'x', self::ASSUMED, $prov,
            $label ? "no measured width for {$label}" : 'no measured width');
        $y = (int) $this->pick($specs, [$map['height'] ?? 'height'], $dy, 'y', self::ASSUMED, $prov,
            $label ? "no measured height for {$label}" : 'no measured height');
        $z = (int) $this->pick($specs, [$map['depth'] ?? 'depth'], $dz, 'z', self::ASSUMED, $prov,
            $label ? "no measured depth for {$label}" : 'no measured depth');

        return ['x' => $x, 'y' => $y, 'z' => $z, 'provenance' => $prov];
    }
}