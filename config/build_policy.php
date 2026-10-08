<?php

/*
 * BUILD POLICY - the single source of truth for every component floor.
 *
 * Why one file: this session produced four separate incidents where the same
 * rule was implemented twice and drifted - the case form factor was derived
 * from specs.form_factor by one consumer and specs.supported_form_factors by
 * another; the form_factor_match rule existed in code with no active row; the
 * assembler published an honest label that PrebuiltController then dropped; and
 * the board gate lived in a per-tier list that could re-admit a forbidden
 * chipset. Four bugs, one cause: policy scattered across consumers.
 *
 * Everything below is read by BOTH scripts/genie-assemble-prebuilts.php and
 * App\Services\AIRecommendationService via App\Services\BuildPolicyGate, so a
 * change here moves the prebuilt tiers and the AI builder together.
 *
 * Every threshold below was measured against production Neon on 2026-10-08 and
 * the measurement is recorded next to the rule. A rule whose data is not
 * populated says so in its comment rather than pretending to be enforceable.
 */

return [

    /*
     * CPU FLOORS.
     *
     * Measured: specs.cores is populated on 234 of 234 active priced CPUs, so
     * core-based rules are safe.
     *
     * AM4 floor is the Ryzen 5 5500. Measured cheapest AM4 rows by price:
     *   Ryzen 3 3200G  GBP 49.00  4c   below floor
     *   Ryzen 3 4100   GBP 51.99  4c   below floor
     *   Ryzen 5 4500   GBP 62.99  6c   below floor
     *   Ryzen 5 5500   GBP 74.99  6c   AT the floor
     *   Ryzen 5 5500GT GBP 112.99 6c   at the floor (and it has iGPU)
     *   Ryzen 5 5600X  GBP 119.99 6c   above
     *
     * The floor is modelled as a MINIMUM MODEL NUMBER, not as a series name,
     * because the same series spans the boundary: Ryzen 5 4500 is under it and
     * Ryzen 5 5500 is on it. A name test must therefore compare the four-digit
     * model, and must NOT use a trailing \b - "5600X" and "5500GT" have no word
     * boundary after the digits, so /\b5600\b/ silently rejects both.
     */
    'cpu' => [
        // AMD AM4: model number >= 5500.
        'am4_min_model' => 5500,

        // Intel: LGA1700 is 12th/13th/14th gen. Anything LGA1700 in the
        // catalogue is 12th gen or newer by definition of the socket, so the
        // floor is enforced as a socket allowlist rather than a series parse
        // that could drift per model.
        // Measured LGA1700 cheapest: Core i3-12100F GBP 79.99 (12th gen).
        'intel_min_gen' => 12,
        'intel_allowed_sockets' => ['LGA1700', 'LGA1851'],
        // Explicitly NOT allowed: LGA1151 and older. Nothing in the active
        // catalogue sits there, so this is a guard, not a filter.
        'intel_blocked_sockets' => ['LGA1151', 'LGA1200'],

        // Prefer 8 cores or more, everywhere. Measured availability of 8c+:
        //   AM4 19 of 42, AM5 21 of 35, LGA1700 39 of 49, LGA1851 14 of 14
        // so this is a preference with headroom on every platform, never a
        // hard gate - making it a gate would empty the AM4 pool.
        'prefer_min_cores' => 8,
    ],

    /*
     * MEMORY.
     *
     * Measured: RAM specs carry ONLY speed and capacity. The module layout lives
     * in the module_config COLUMN (e.g. "2 x 8GB") - there is no specs.modules
     * key at all, which is the same class of miss as the case form factor.
     *
     * Dual channel is MANDATORY, not preferred. A single 16GB stick halves the
     * memory bandwidth available to the CPU, and on an APU it also throttles
     * the integrated graphics, which share system memory.
     *
     * Speed floor is per generation. NOTE the parse: specs.speed is formatted
     * "DDR4-3200", so stripping every non-digit yields 43200 - the 4 from DDR4
     * concatenated with 3200. Always strip the generation token FIRST.
     */
    'memory' => [
        'min_total_gb' => 16,
        // "No single sticks ever" - expressed as a MODULE COUNT, not a layout
        // string. An exact "2 x 8" match was measured to reject 159 perfectly
        // good 2x16 32GB kits, because the rule is that memory must be dual
        // channel and at least 16GB, not that it must be exactly 2x8.
        // Measured on active RAM: 60 single-stick kits, 315 dual-channel.
        'require_dual_channel' => true,
        'min_module_count' => 2,
        // Informational: the canonical 16GB dual-channel layout. Recorded so the
        // storefront can describe it, not used to reject anything.
        'canonical_16gb_layout' => '2 x 8',
        'min_speed' => [
            'DDR4' => 3200,
            'DDR5' => 4800,
        ],
        // Generation is derived from the socket, never chosen freely:
        // AM4 and LGA1700 are DDR4 platforms, AM5 and LGA1851 are DDR5.
        'socket_generation' => [
            'AM4' => 'DDR4',
            'LGA1700' => 'DDR4',
            'AM5' => 'DDR5',
            'LGA1851' => 'DDR5',
        ],
    ],

    /*
     * STORAGE.
     *
     * M.2 (NVMe) is PREFERRED. The floor is a 500GB SATA SSD, not a 500GB hard
     * disk and not a spinning platter of any size - the cheapest compliant
     * build must still boot fast.
     *
     * Measured capacity histogram on active storage: 500GB n=28, 512GB n=19,
     * 1024GB n=104. So a 500GB floor leaves a real pool.
     *
     * Type is read from the storage_type COLUMN plus specs. Measured: 0 storage
     * rows carry specs.type, so a specs-only type check matches nothing.
     */
    'storage' => [
        'min_capacity_gb' => 500,
        // MEASURED, and this is the field that matters:
        //   storage_type column  = "SSD" on 338 of 338 active priced rows
        //   specs.interface/type = populated on 0 of 338
        //   interface column     = "M.2 PCIe 4.0 X4", "SATA 6.0 Gb/s", ...
        // So SSD-ness comes from storage_type and the M.2-vs-SATA distinction
        // comes from the interface COLUMN. A gate that reads specs.interface
        // matches nothing and rejected 338 of 338 rows on the first run.
        'ssd_types' => ['SSD'],
        'prefer_interface' => ['M.2', 'NVME'],
        'allowed_interface_prefixes' => ['M.2', 'NVME', 'SATA', 'PCIe'],
    ],

    /*
     * GRAPHICS.
     *
     * Floor, for any build with a discrete card: Intel Arc B570, NVIDIA RTX
     * 3050 8GB, or AMD RX 7000 series with 8GB.
     *
     * Measured: specs.memory is populated on 306 of 306 active priced GPUs, so
     * the 8GB floor is enforceable rather than a guess. 290 qualify; the 16
     * that do not are 3 cards at 4GB and 13 at 6GB (RTX 3050 6GB, Arc A310,
     * GTX 1660 class). Those must NOT be sold into any band.
     *
     * The 8GB floor is SEPARATE from the performance tier in
     * AIRecommendationService::gpuPerformanceTier(). The tier says how fast a
     * card is; the floor says whether it is acceptable at all. A tier-2 card
     * with 6GB fails the floor and must be excluded even though its tier would
     * otherwise qualify it for a 1080p band.
     *
     * APU builds are exempt: they have no discrete card, and the floor exists to
     * stop a weak card being passed off as sufficient.
     */
    'gpu' => [
        'min_vram_gb' => 8,
        'exempt_when_integrated_graphics' => true,
        /*
         * A FLOOR, not an allow list. The first attempt listed three ALLOW
         * patterns and rejected 271 of 306 cards, including every RTX 50-series
         * board - the rule is a minimum, so anything at or above the floor must
         * pass.
         *
         * So cards are rejected when they match a known BELOW-floor family, or
         * when VRAM is under 8GB. Anything unlisted with 8GB+ passes.
         *
         * Measured families (active priced GPUs):
         *   RTX 50-series 160 (all >=8GB)  RTX 40-series 12 (all >=8GB)
         *   RX 7000        15 (all >=8GB)  RX 6000   10 (8 of 10 >=8GB)
         *   RTX 30-series  16 (10 >=8GB)   Arc       11 (8 >=8GB)
         *   GTX             6 (2 >=8GB)    other     76 (75 >=8GB)
         */
        'reject_below_floor' => [
            // Pre-RTX NVIDIA generations. The RTX 3050 is the floor, so the
            // 20-series and the GTX lines are all below it.
            '/\bGTX\s*(9|10|16|20)\d{2}/i',
            '/\bGT\s*\d{3,4}\b/i',
            '/\bRTX\s*20\d{2}/i',
            '/\bRTX\s*30[0-4]\d0?\b/i',
            // AMD below the RX 7000 floor.
            '/\bRX\s*[5-6]\d{3}\b/i',
            // Intel Arc below B570.
            '/\bARC\s*(A310|A380|A580|A750)\b/i',
            // Older workstation cards that are not gaming parts.
            '/\b(QUADRO|RTX\s*A\d{4}|RADEON\s*PRO)\b/i',
        ],
        // The RTX 3050 ships in 6GB and 8GB. The names are identical, so the
        // VRAM floor is the only thing that separates them.
        'min_models_note' => 'RTX 3050 6GB shares the name of the 8GB part - the VRAM floor is the only thing that separates them.',
    ],

    /*
     * BRANDS TO AVOID, across every category.
     *
     * Measured first: none of these appeared anywhere in the codebase, so this
     * list is new policy rather than an existing rule being re-applied.
     *
     * "Builder series" is matched as a substring because it appears inside
     * model names rather than as a brand of its own.
     *
     * Gigabyte is ALLOWED but narrowed to its AORUS and Elite lines. Applied as
     * an exception list, not a blocklist entry, so the AORUS/Elite carve-out
     * stays legible next to the rule it modifies.
     */
    'brands' => [
        /*
         * Substring match against a LOWERCASED name.
         *
         * Spelling matters and is not cosmetic. "Fanxiang" as written in the
         * instruction does NOT match the catalogue's "FanXiang": lowercased
         * that is "fanxiang", so the original entry matched zero rows and a
         * FanXiang SSD was being offered as a clean alternative to a blocked
         * one. Both spellings are listed rather than "corrected", so the rule
         * cannot silently depend on which form the catalogue happens to use.
         *
         * Measured row counts on active priced parts: Silicon Power 34,
         * FanXiang 0 under "fanixiang" and non-zero under "fanxiang", OLOY 0,
         * Gigastone 0, Colorful 0, "Builder" 0. Four of the six entries are
         * currently inert because those brands are not in the catalogue; they
         * are kept so the rule still applies if the catalogue changes.
         */
        'blocked_substrings' => [
            'Silicon Power',
            'FanXiang',
            'fanxiang',
            'Fanixiang',
            'Gigastone',
            'OLOY',
            'Colorful',
            'Builder',
        ],
        // Blocked unless the name also matches one of these.
        //
        // Boss decision, 2026-10-08: AORUS, plus the mainstream GAMING and
        // WINDFORCE lines. Everything else Gigabyte is blocked.
        //
        // Measured before that decision: "ELITE" appears on 0 Gigabyte rows
        // that do not also carry "AORUS" - it is part of the AORUS naming
        // (B850 AORUS ELITE), not a standalone line, so it is listed for
        // clarity rather than because it widens the set.
        //
        // GAMING and WINDFORCE are Gigabyte-specific line names, so matching
        // them is safe here: the conditional block only applies to a row whose
        // name already contains "Gigabyte", so an ASUS TUF GAMING or an MSI MAG
        // board is unaffected by these patterns.
        //
        // Confirmed by the Boss: applying this to the three already-published
        // prebuilts is expected - all three carried a blocked part.
        'conditional_block' => [
            'Gigabyte' => ['/\bAORUS\b/i', '/\bGAMING\b/i', '/\bWINDFORCE\b/i', '/\bELITE\b/i'],
        ],
        'match' => 'name_and_manufacturer',
    ],

    /*
     * POWER SUPPLY.
     *
     * PREFER Gold 80+; if the budget will not reach a Gold, take Bronze 80+.
     *
     * MEASURED LIMITATION, and the reason this is a preference and not a gate:
     * there is NO efficiency field anywhere for PSUs. PSU specs carry only
     * form_factor, width, height, depth and dimension_source; no column holds a
     * rating either. Only 26 of 287 active priced PSU names mention a rating at
     * all ("Cooler Master MWE Gold V2", "Fractal Design Ion 3 Gold 750W").
     *
     * So the rating is read from the name and scored, and a PSU with no rating
     * in its name is treated as UNKNOWN and ranked below Bronze rather than
     * being excluded. Making this a hard gate would reject 261 of 287 PSUs and
     * take the builder offline; making it fail CLOSED would be dishonest about
     * data we do not have.
     *
     * Backfilling a real rating field is the only way to turn this into a
     * guarantee. Until then the storefront must not claim an efficiency rating
     * it cannot evidence.
     */
    'psu' => [
        'prefer' => ['GOLD', 'TITANIUM', 'PLATINUM'],
        'fallback' => ['BRONZE', 'SILVER'],
        'require_80plus_brand_text' => true,
        'unknown_rating_rank' => 'below-bronze',
        'min_headroom_multiplier' => 1.4,
        // Over-provisioning ceiling. The absolute floor exists because scaling
        // the ceiling purely by draw capped a 155W APU box at 341W and the
        // catalogue records nothing between 217W and 341W, so the esports tier
        // was skipped for "no PSU" while 500W is the smallest sensible unit.
        'absolute_max_watts' => 1000,
        'over_provision_ceiling' => 2.2,
    ],

    /*
     * GPU SELECTION PRIORITY.
     *
     * "Always prioritise 8 core CPU and best GPU for money" - expressed as an
     * explicit ordering so the assembler's greedy cheapest-first selection
     * cannot quietly produce a weak-CPU / strong-GPU or vice versa machine.
     */
    'selection_priority' => [
        'prefer_cores_first' => true,
        'prefer_gpu_value' => true,
        // Within the memory and storage pools, order by what the buyer gets
        // rather than by lowest price.
        'ram_prefer' => ['speed', 'capacity'],
        'storage_prefer' => ['interface', 'capacity'],
    ],

    /*
     * AI BUILDER OUTPUT: three systems per usage target.
     *
     * One BELOW budget, one CLOSEST to budget, one BEST performance for the
     * money. The middle one must land within +/- GBP 150 of the stated budget.
     *
     * Expressed as config so the three-slot output cannot disagree with the
     * floors above; every candidate still has to pass them.
     */
    'ai_output' => [
        'slots' => [
            ['key' => 'under_budget', 'label' => 'Under budget', 'rule' => 'cheapest_valid'],
            ['key' => 'at_budget', 'label' => 'At your budget', 'rule' => 'closest_to_budget', 'tolerance_gbp' => 150],
            ['key' => 'best_value', 'label' => 'Best performance for the money', 'rule' => 'best_score_per_gbp'],
        ],
        'budget_tolerance_gbp' => 150,
        'resolutions' => ['1080P', '1440P', '4K'],
    ],
];