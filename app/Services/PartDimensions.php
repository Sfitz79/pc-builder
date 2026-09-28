<?php

namespace App\Services;

/**
 * Resolves physical dimensions (mm) for the 3D build viewport.
 *
 * Real spec values come from the catalogue's `specs` JSON (e.g.
 * `max_gpu_length`, `height`/`width`/`depth` on cases). Anything missing falls
 * back to standards for the category/form-factor so the viewport always has a
 * renderable, true-to-scale model without every catalogue row carrying dims.
 */
class PartDimensions
{
    /**
     * Physical millimetre dims for a component. Keys: x (width), y (height),
     * z (depth). Returned as [x, y, z] box dims plus optional placement hints
     * used by the viewport (slot offset, thickness, radiator length...).
     */
    public function resolve(?string $category, ?string $name, array $specs = []): array
    {
        $slug = trim(mb_strtolower((string) $name));

        return match ($category) {
            'case'      => $this->caseDims($specs),
            'gpu'       => $this->gpuDims($specs),
            'cpu'       => $this->resolveFromSpecs($specs, 40, 90, 9, ['height' => 'cpu_height', 'width' => 'cpu_width']),
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

    protected function caseDims(array $specs): array
    {
        // Prioritise explicit case dims; seed from known specs otherwise.
        $h = $specs['height'] ?? 460;
        $w = $specs['width'] ?? 230;
        $d = $specs['depth'] ?? 460;

        return [
            'x' => $w, 'y' => $h, 'z' => $d,
            'glass' => $specs['glass_panels'] ?? 'side',
            'rad_top' => $specs['rad_top'] ?? 360,
            'rad_front' => $specs['rad_front'] ?? 360,
            'max_gpu' => $specs['max_gpu_length'] ?? max(320, (int) $d - 80),
            'max_cooler' => $specs['max_cpu_cooler_height'] ?? 170,
            'form' => $specs['form_factor'] ?? ($specs['supported_form_factors'][0] ?? 'mid-tower'),
        ];
    }

    protected function gpuDims(array $specs): array
    {
        $length = (int) ($specs['length'] ?? 300);
        $height = (int) ($specs['height'] ?? 120);
        $slots = (int) ($specs['thickness_slots'] ?? 2.75);

        return [
            'x' => $length, 'y' => $height, 'z' => $slots * 20.3,
            'slots' => $slots,
            'tflops' => isset($specs['tflops']) ? (float) $specs['tflops'] : null,
            'memory' => $specs['memory'] ?? null,
        ];
    }

    protected function motherboardDims(array $specs): array
    {
        $form = $specs['form_factor'] ?? 'ATX';

        $std = match (strtoupper((string) $form)) {
            'MINI-ITX', 'MINI_ITX' => [170, 25, 170],
            'MICRO-ATX', 'MICRO_ATX', 'M-ATX' => [244, 25, 244],
            'E-ATX', 'EATX' => [305, 25, 277],
            default => [305, 25, 244],
        };

        return ['x' => $std[0], 'y' => $std[1], 'z' => $std[2], 'form' => $form];
    }

    protected function psuDims(array $specs): array
    {
        $form = $specs['form'] ?? $specs['form_factor'] ?? 'ATX';

        return match (strtoupper((string) $form)) {
            'SFX' => ['x' => 100, 'y' => 63.5, 'z' => 125, 'form' => 'SFX'],
            default => ['x' => 140, 'y' => 86, 'z' => 150, 'form' => 'ATX'],
        };
    }

    protected function coolerDims(array $specs, string $slug = ''): array
    {
        $isAio = isset($specs['type']) && preg_match('/aio|liquid/i', (string) $specs['type'])
            || str_contains($slug, 'liquid')
            || str_contains($slug, 'arctic liquid')
            || str_contains($slug, 'kraken')
            || str_contains($slug, 'aio');

        if ($isAio) {
            $radLen = (int) ($specs['radiator_length'] ?? $specs['rad_length'] ?? 360);
            $fans = (int) ($specs['fan_count'] ?? match ($radLen) {
                120 => 1, 140 => 1, 240 => 2, 280 => 2, default => 3,
            });

            return [
                'x' => $radLen, 'y' => $fans * 25, 'z' => 52,
                'type' => 'aio',
                'radiator_length' => $radLen,
                'fan_count' => $fans,
            ];
        }

        // Air tower
        return [
            'x' => 125, 'y' => (int) ($specs['height'] ?? 160), 'z' => 135,
            'type' => 'air',
        ];
    }

    protected function resolveFromSpecs(array $specs, int $dx, int $dy, int $dz, array $map = []): array
    {
        $x = (int) ($specs[$map['width'] ?? 'width'] ?? $dx);
        $y = (int) ($specs[$map['height'] ?? 'height'] ?? $dy);
        $z = (int) ($specs[$map['depth'] ?? 'depth'] ?? $dz);

        return ['x' => $x, 'y' => $y, 'z' => $z];
    }
}