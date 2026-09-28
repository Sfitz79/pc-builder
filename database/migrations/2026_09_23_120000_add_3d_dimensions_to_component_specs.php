<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill physical dimensions into existing components' `specs` JSON so the
 * 3D build viewport renders true-to-scale without waiting on vendor data.
 * Covers the live catalogue at time of writing (cases, GPUs, coolers, PSUs).
 */
return new class extends Migration
{
    private const DIMS = [
        // cases: height (incl. feet/top) x width x depth, glass panel, rad support mm
        'lian-li-o11-vision' => ['height' => 471, 'width' => 288, 'depth' => 426, 'glass_panels' => 'front_side', 'rad_top' => 360, 'rad_front' => 360],
        'nzxt-h6-flow-rgb' => ['height' => 435, 'width' => 287, 'depth' => 415, 'glass_panels' => 'side', 'rad_top' => 360, 'rad_front' => 280],
        'fractal-north' => ['height' => 447, 'width' => 215, 'depth' => 445, 'glass_panels' => 'side', 'rad_top' => 240, 'rad_front' => 240],
        'hyte-y70' => ['height' => 485, 'width' => 320, 'depth' => 420, 'glass_panels' => 'front_side', 'rad_top' => 360, 'rad_front' => 360],

        // gpus: length x height mm, thickness slots
        'rtx-5070-ti' => ['length' => 304, 'height' => 120, 'thickness_slots' => 3],
        'rtx-5080' => ['length' => 304, 'height' => 120, 'thickness_slots' => 3],
        'rtx-5090' => ['length' => 336, 'height' => 135, 'thickness_slots' => 3],
        'rx-9070-xt' => ['length' => 267, 'height' => 120, 'thickness_slots' => 2.5],

        // coolers
        'noctua-nh-d15' => ['type' => 'air', 'height' => 165],
        'arctic-liquid-freezer-iii-360' => ['type' => 'aio', 'radiator_length' => 360, 'fan_count' => 3],
        'deepcool-ak620' => ['type' => 'air', 'height' => 160],

        // psus
        '650w-80-gold' => ['form' => 'ATX'],
        '850w-80-gold' => ['form' => 'ATX'],
        '1000w-80-gold' => ['form' => 'ATX'],
    ];

    public function up(): void
    {
        $rows = DB::table('components')->whereIn('slug', array_keys(self::DIMS))->get(['id', 'slug', 'specs']);

        foreach ($rows as $row) {
            $specs = is_string($row->specs) && $row->specs ? json_decode($row->specs, true) : [];
            $specs = is_array($specs) ? $specs : [];
            $specs = array_merge($specs, self::DIMS[$row->slug]);
            DB::table('components')->where('id', $row->id)->update(['specs' => json_encode($specs)]);
        }
    }

    public function down(): void
    {
        foreach (self::DIMS as $slug => $dims) {
            $rows = DB::table('components')->where('slug', $slug)->get(['id', 'specs']);
            foreach ($rows as $row) {
                $specs = is_string($row->specs) && $row->specs ? json_decode($row->specs, true) : [];
                if (! is_array($specs)) {
                    continue;
                }
                $specs = array_diff_key($specs, $dims);
                DB::table('components')->where('id', $row->id)->update(['specs' => json_encode($specs)]);
            }
        }
    }
};