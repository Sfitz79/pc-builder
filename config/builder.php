<?php

/**
 * AI Builder presentation flags.
 *
 * WHY THIS EXISTS RATHER THAN CALLING env() IN A BLADE VIEW
 * -------------------------------------------------------
 * The interactive 3D viewport was gated with `env('PCTG_ENABLE_3D_VIEWPORT')`
 * directly in the view. That is wrong twice over:
 *
 *  1. env() is documented to be read from CONFIG files only. Once the config is
 *     cached (`php artisan config:cache`, which production almost always runs),
 *     every env() call outside config/*.php returns null. The flag would then be
 *     permanently false with no way to turn it back on - the exact opposite of
 *     what a feature flag is for.
 *  2. A flag that is read on every page render is a config value by definition.
 *
 * @see resources/views/builder/partials/build-3d.blade.php
 */
return [

    /*
     * The interactive three.js viewport of the configured build.
     *
     * DEFAULT OFF, DELIBERATELY. Measured 2026-10-06 with
     * scripts/verify-3d-render.mjs, which renders the real scene in a headless
     * browser and screenshots the framebuffer. The geometry is dimensionally
     * correct - 230 x 432 x 460 mm, 372 meshes, every mesh added, zero page
     * errors - and it passes all ten of its own automated checks. The captured
     * frame is still not recognisable as a PC: a large featureless slab where
     * the graphics card should be, several intersecting translucent planes, and
     * no readable case, fan or memory module.
     *
     * Those automated checks cannot catch that and never will, because they
     * assert "not empty", "is lit" and "has N colour buckets" - all of which
     * pass comfortably on an unusable frame. A green result that is quietly
     * worse than intended is a failure, not a pass.
     *
     * This was verified LIVE on pctechguy.app/builder, so a customer could open
     * it. The approved customer-facing visual (Storefront Render) is unaffected
     * and stays available.
     *
     * Set PCTG_ENABLE_3D_VIEWPORT=1 to restore the canvas while the
     * asset-driven rebuild is built: authored low-poly .glb parts with an
     * interaction mesh for raycasting and a named RGB_Zone sub-mesh, rather
     * than procedural primitives.
     */
    'enable_3d_viewport' => (bool) env('PCTG_ENABLE_3D_VIEWPORT', false),

    /*
     * Asset credits that MUST be displayed alongside any customer-facing image
     * derived from a third-party 3D asset.
     *
     * WHY A REGISTRY AND NOT A RUNTIME LOOKUP
     * ---------------------------------------
     * AssetLicenceGate::evaluate() returns `attributionRequired`, but as of
     * 2026-10-06 nothing consumed that value anywhere in the app - it appeared
     * only inside the gate itself. The gate could therefore say "attribution
     * required" and no credit would ever be shown, which is a silent licence
     * breach on a revenue-bearing storefront rather than a cosmetic bug.
     *
     * So attribution is declared here, in config, and rendered by
     * resources/views/builder/partials/asset-credits.blade.php.
     *
     * THE RULE THIS ENFORCES
     * ----------------------
     * No CC-BY asset may be used in a customer-facing render unless it has an
     * entry below. If it is missing, the credit cannot be shown, so the asset
     * must not be used. Registering it IS the act of accepting the obligation.
     *
     * Only licences AssetLicenceGate allows are permitted here:
     *   CC0 / Public Domain  - no credit required, but registering is harmless
     *   CC-BY 3.0 / 4.0      - credit required, must be visible
     *   CC-BY-SA             - rejected by the gate: share-alike reaches output
     *   *NC / *ND / editorial / marketplace - rejected, never register one
     *
     * @see \App\Services\ThreeD\AssetLicenceGate
     */
    'asset_credits' => [
        // Example of the required shape. Nothing is registered yet because no
        // third-party asset is currently used in any customer-facing render.
        //
        // 'case-mid-tower' => [
        //     'title'  => 'Generic mid-tower case',
        //     'author' => 'Author name as credited on the source page',
        //     'licence'=> 'CC-BY 4.0',
        //     'source' => 'https://sketchfab.com/3d-models/...',
        // ],
    ],

];