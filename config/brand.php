<?php

/**
 * Brand identity — the single source of truth.
 *
 * WHY THIS EXISTS
 * ---------------
 * The site was mixing three different names in three different places, and the
 * header disagreed with everything else:
 *
 *   - header logo text   : "PCTechGuy Online"
 *   - <title>            : "... | PCTG Builder"
 *   - og:site_name       : "PCTG Builder"
 *   - footer             : "© PCTG Builder"
 *   - JSON-LD schema     : "PCTechGuy Online"
 *   - the app itself     : referred to as "PCTG Builder" in several guides
 *
 * So the header said one thing and every search result, social preview and
 * footer said another, while the structured data said a third.
 *
 * THE DISTINCTION, as the Boss defines it (2026-10-07):
 *
 *   BRAND  = PCTechGuyOnline, abbreviated PCTG. This is the business. It is
 *            what the customer buys from, so it is what the <title>,
 *            og:site_name, footer and schema must all say.
 *
 *   APP    = PC Builder. This is the tool — the AI configurator. It is what
 *            gets a mention in body copy and guide text, and it is not the
 *            company name.
 *
 * Conflating them is what produced the mess, so they are separate keys here
 * and neither is used where the other belongs.
 *
 * WHY NOT config('app.name')
 * --------------------------
 * `config/app.php` reads `env('APP_NAME', 'PCTG Builder')`, and
 * `.env.vercel.prod` sets `APP_NAME=""`. An empty string is a PRESENT value,
 * so env() returns "" and the default never fires — the fallback chain does
 * nothing at all, which is a false sense of safety. Using `?:` here means an
 * empty or whitespace-only APP_NAME falls through to the real app name rather
 * than rendering an empty brand.
 */
return [

    /*
     * The business. Appears in <title>, og:site_name, twitter:site, the
     * footer, the JSON-LD schema and the header lockup.
     */
    'name' => env('BRAND_NAME') ?: 'PCTechGuyOnline',

    /*
     * Abbreviation for tight spaces — the header mark, badges, chips.
     */
    'short' => env('BRAND_SHORT') ?: 'PCTG',

    /*
     * The product, not the company. Used for "Start building in PC Builder"
     * and similar body copy. Never used as the <title> suffix.
     */
    'app' => env('APP_NAME') ?: 'PC Builder',

    /*
     * Matches the supplied wordmark asset at
     * public/img/brand/pctg-tagline.png. If the tagline changes, that asset
     * changes too — the two must not drift apart.
     */
    'tagline' => 'The Gamers Edge',

    /*
     * Used in meta descriptions.
     */
    'description' => 'Custom gaming PCs configured to your own spec, built and '
        . 'tested in the UK. Compatibility checked, two-year warranty, GBP prices '
        . 'that include VAT.',

];