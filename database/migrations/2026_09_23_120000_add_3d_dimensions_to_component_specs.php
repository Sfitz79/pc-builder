<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill physical dimensions into existing components' `specs` JSON so the
 * 3D build viewport renders true-to-scale without waiting on vendor data.
 *
 * SLUGS ARE HASH-SUFFIXED. The catalogue importer generates slugs like
 * `lian-li-o11-vision-48d17f`, so a bare product slug matches nothing. The keys
 * below were verified against production Neon on 2026-09-29 via
 * scripts/genie-probe-slug-discovery.php.
 *
 * SAFETY: up() is idempotent (array_merge of the same keys) and every key here
 * is an exact, verified production slug, so a missed row can never be silently
 * half-applied. slugsMatched() logs coverage; a large drop means slugs drifted
 * again and the backfill should be re-verified rather than trusted.
 */
return new class extends Migration
{
    /**
     * Dimensions in mm. Sources: manufacturer spec sheets / case specs.
     * GPUs: length x height, thickness in slots. Cases: H x W x D, radiator
     * support in mm, glass panel layout. Coolers: type + height / radiator size.
     */
    private const DIMS = [
        // cases (verified slugs, category 7)
        'lian-li-o11-vision-48d17f' => ['height' => 471, 'width' => 288, 'depth' => 426, 'glass_panels' => 'front_side', 'rad_top' => 360, 'rad_front' => 360],
        'nzxt-h6-flow-rgb-2e2909' => ['height' => 435, 'width' => 287, 'depth' => 415, 'glass_panels' => 'side', 'rad_top' => 360, 'rad_front' => 280],

        // gpus (verified slugs, category 3)
        'pny-vcg508016tfxpb1-94cdb0' => ['length' => 304, 'height' => 120, 'thickness_slots' => 3],
        'pny-vcg507012tfxpb1-084f32' => ['length' => 242, 'height' => 111, 'thickness_slots' => 2],
        'gigabyte-gv-r9070xtgaming-16gd-5c167f' => ['length' => 320, 'height' => 130, 'thickness_slots' => 3],

        // coolers (verified slugs, category 8)
        'noctua-nh-d15-6ae3a0' => ['type' => 'air', 'height' => 165],
        'arctic-liquid-freezer-iii-pro-a-rgb-360-b3b6b0' => ['type' => 'aio', 'radiator_length' => 360, 'fan_count' => 3],
        'deepcool-ak620-digital-baf84c' => ['type' => 'air', 'height' => 160],
    ];

    /**
     * Keys that were in the original version of this migration but match no
     * production row. Kept as documentation of what was investigated and
     * rejected, so nobody re-adds them without verifying first.
     *
     * @var list<string>
     */
    private const RETIRED_KEYS = [
        'fractal-north',            // no exact slug; 10 "north" rows are other Fractal models
        'hyte-y70',                 // no exact slug; "y70" matched 21 unrelated rows
        'rtx-5070-ti',              // no Ti in catalogue; only a PNY 5070 exists
        'rtx-5080',                 // superseded by the hashed PNY slug above
        'rtx-5090',                 // zero rows in catalogue
        'rx-9070-xt',               // superseded by the hashed Gigabyte slug above
        'noctua-nh-d15',            // superseded by the hashed slug above
        'arctic-liquid-freezer-iii-360', // only "-pro-360" variants exist
        'deepcool-ak620',           // only ZERO DARK / DIGITAL variants exist
        '650w-80-gold',             // zero rows: no slug contains a wattage
        '850w-80-gold',             // zero rows
        '1000w-80-gold',            // zero rows
    ];

    /**
     * Log how many DIMS keys actually resolve, so a silent regression is
     * visible in the migration log instead of looking like success.
     */
    private function slugsMatched(): int
    {
        $matched = DB::table('components')
            ->whereIn('slug', array_keys(self::DIMS))
            ->count();

        $expected = count(self::DIMS);
        if ($matched < $expected) {
            \Log::warning('3D dimension backfill: only ' . $matched . '/' . $expected
                . ' DIMS slugs matched production; the remainder were skipped. '
                . 'Catalogue slugs have likely drifted - re-verify before trusting the viewport.', [
                    'matched' => $matched,
                    'expected' => $expected,
                ]);
        }

        return $matched;
    }

    public function up(): void
    {
        $this->slugsMatched();

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
        // Only ever remove the keys this migration is responsible for, and only
        // on rows it actually matched. Never array_diff_key against a stale list.
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