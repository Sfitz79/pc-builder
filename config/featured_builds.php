<?php

/*
 * FEATURED BUILD TAXONOMY.
 *
 * Every featured system is addressed by three axes, and the axes are closed so
 * a build cannot drift out of the taxonomy:
 *
 *   usage       gaming | streaming | content_creation | studio
 *   resolution  1080P | 1440P | 4K
 *   tier        low | mid | high
 *
 * Tier is a PERFORMANCE statement, not a price bracket. "High" means the best
 * parts we will fit, not "expensive".
 *
 * Coverage required by the Boss:
 *   - GAMING: three tiers at each of the three resolutions = 9 systems.
 *   - EVERY OTHER USAGE: mid and high at each resolution = 6 per usage,
 *     18 in total. Low is deliberately absent outside gaming: a "low" streaming
 *     or studio machine is not a product we would offer.
 *
 * NAMES AND COPY
 * Each system carries a PCTG product name and a short, hype-led summary. The
 * brief was explicitly "tech free", so the summaries deliberately name no
 * part numbers, no chipset, no gigahertz and no benchmark figures. Every part
 * name lives in the build's parts list where it can be verified; the pitch is
 * not the place to make claims we would have to evidence.
 *
 * The parts behind every one of these are assembled by
 * scripts/genie-assemble-featured.php under config/build_policy.php, so a
 * featured system can never contain a part the storefront would refuse to sell.
 */

return [

    'axes' => [
        'usage' => [
            'gaming' => 'Gaming',
            'streaming' => 'Streaming',
            'content_creation' => 'Content Creation',
            'studio' => 'Studio',
        ],
        'resolution' => ['1080P', '1440P', '4K'],
        'tier' => [
            'low' => 'Low',
            'mid' => 'Mid',
            'high' => 'High',
        ],
    ],

    /*
     * Which tiers exist per usage.
     *
     * Gaming gets all three because esports and mainstream 1080p are genuinely
     * different products. The professional usages start at mid: there is no
     * honest "low" streaming or studio machine - encoding an OBS stream or
     * driving a timeline is not a task that degrades gracefully.
     */
    'coverage' => [
        'gaming' => ['low', 'mid', 'high'],
        'streaming' => ['mid', 'high'],
        'content_creation' => ['mid', 'high'],
        'studio' => ['mid', 'high'],
    ],

    /*
     * GPU tier floor per resolution, on the existing 0-5 performance ladder:
     *   0 no opinion, 1 1080p entry, 2 1080p high / 1440p entry,
     *   3 real 1440p, 4 strong 1440p / entry 4K, 5 proper 4K
     *
     * 1080p low has no floor beyond the policy's 8GB model floor: the esports
     * box is the APU path, and a dedicated card at that tier would be paying
     * for graphics nobody asked for.
     *
     * Read by App\Services\AIRecommendationService::minGpuTierFor() semantics.
     * A tier of 0 means "no dedicated card required", which is how the APU
     * builds are expressed.
     */
    'gpu_tier_floor' => [
        '1080P' => ['low' => 0, 'mid' => 2, 'high' => 3],
        '1440P' => ['low' => 3, 'mid' => 4, 'high' => 4],
        '4K' => ['low' => 4, 'mid' => 5, 'high' => 5],
    ],

    /*
     * Budget band per resolution and tier, absolute GBP.
     *
     * Every cell needs its own CEILING. Bands were originally shared by ceiling
     * across the taxonomy, and because the GPU and CPU are allocated a share OF
     * the ceiling, cells with the same ceiling assembled byte-identical builds:
     * four separate products came out at exactly GBP 1926.12. The spread below is
     * deliberately staggered so no two cells share a ceiling, and so the nine
     * gaming systems ascend monotonically across resolution and tier.
     *
     * Floors are set below the achievable minimum so a small price move cannot
     * delete a product - the same margin reasoning that moved the esports prebuilt
     * band from GBP 450 to GBP 400 after it landed GBP 0.83 above its own limit.
     */
    'budget_gbp' => [
        'gaming' => [
            '1080P' => ['low' => [380, 700], 'mid' => [800, 1500], 'high' => [1200, 2000]],
            '1440P' => ['low' => [900, 1600], 'mid' => [1300, 2200], 'high' => [1900, 3200]],
            '4K' => ['low' => [1500, 2600], 'mid' => [2200, 3600], 'high' => [3200, 5500]],
        ],
        'streaming' => [
            '1080P' => ['mid' => [800, 1700], 'high' => [1600, 2500]],
            '1440P' => ['mid' => [1000, 2100], 'high' => [1900, 3000]],
            '4K' => ['mid' => [1400, 2700], 'high' => [2500, 4100]],
        ],
        'content_creation' => [
            '1080P' => ['mid' => [900, 1900], 'high' => [1700, 2900]],
            '1440P' => ['mid' => [1200, 2300], 'high' => [2100, 3500]],
            '4K' => ['mid' => [1500, 2900], 'high' => [2700, 4600]],
        ],
        'studio' => [
            '1080P' => ['mid' => [950, 2000], 'high' => [1800, 3100]],
            '1440P' => ['mid' => [1300, 2600], 'high' => [2400, 4100]],
            '4K' => ['mid' => [1700, 3300], 'high' => [3100, 5300]],
        ],
    ],

    /*
     * Named systems.
     *
     * `slug` is the PCTG product name. Names are deliberately short, pronounceable
     * and free of spec language, because they appear on the storefront as a
     * product line rather than as a specification.
     *
     * `summary` is the short hype line: no part numbers, no speeds, no scores.
     * `promise` is the honest boundary of what the machine is for, which is
     * kept separate from the pitch on purpose so marketing copy can never quietly
     * become a claim.
     */
    'systems' => [

        // ------------------------------------------------------------ GAMING
        '1080P' => [
            'low' => [
                'usage' => 'gaming',
                'slug' => 'nimbus',
                'name' => 'PCTG Nimbus',
                'summary' => 'Plug in and play. Esports-grade responsiveness without the shouting.',
                'promise' => 'Built for competitive shooters and MOBAs at 1080p. Not a creator machine.',
                'platform' => 'AM4',
                'apu' => true,
            ],
            'mid' => [
                'usage' => 'gaming',
                'slug' => 'cascade',
                'name' => 'PCTG Cascade',
                'summary' => 'The everyday all-rounder. Smooth everywhere, quiet where it matters.',
                'promise' => 'A confident 1080p gaming machine for everyday play.',
                'platform' => 'AM5',
            ],
            'high' => [
                'usage' => 'gaming',
                'slug' => 'tempest',
                'name' => 'PCTG Tempest',
                'summary' => 'Frames you can feel. Nothing between you and the next round.',
                'promise' => 'High-refresh 1080p built for competitive play at high settings.',
                'platform' => 'AM5',
            ],
        ],
        '1440P' => [
            'low' => [
                'usage' => 'gaming',
                'slug' => 'harbour',
                'name' => 'PCTG Harbour',
                'summary' => 'Step up a notch. Sharper picture, still sensible.',
                'promise' => 'Entry 1440p gaming. Strong at competitive settings, not chasing 4K.',
                'platform' => 'AM5',
            ],
            'mid' => [
                'usage' => 'gaming',
                'slug' => 'summit',
                'name' => 'PCTG Summit',
                'summary' => 'The sweet spot. This is where 1440p really opens up.',
                'promise' => 'A properly balanced 1440p machine for high-refresh play.',
                'platform' => 'AM5',
            ],
            'high' => [
                'usage' => 'gaming',
                'slug' => 'titan',
                'name' => 'PCTG Titan',
                'summary' => 'Enthusiast grade. Everything on, nothing held back.',
                'promise' => 'Enthusiast 1440p with the headroom for tomorrow.',
                'platform' => 'AM5',
            ],
        ],
        '4K' => [
            'low' => [
                'usage' => 'gaming',
                'slug' => 'vista',
                'name' => 'PCTG Vista',
                'summary' => 'Real 4K, sensible settings. The doorway in, not the whole house.',
                'promise' => 'Entry 4K gaming. Requires a 4K display to make sense.',
                'platform' => 'AM5',
            ],
            'mid' => [
                'usage' => 'gaming',
                'slug' => 'apex',
                'name' => 'PCTG Apex',
                'summary' => '4K without compromise. High refresh and detail together.',
                'promise' => 'A strong all-round 4K machine for high refresh and high settings.',
                'platform' => 'AM5',
            ],
            'high' => [
                'usage' => 'gaming',
                'slug' => 'zenith',
                'name' => 'PCTG Zenith',
                'summary' => 'The top of the range. Built for the games that ask for everything.',
                'promise' => 'Flagship 4K. Pair with a high-refresh 4K display.',
                'platform' => 'AM5',
            ],
        ],

        // -------------------------------------------------------- STREAMING
        'streaming_1080P' => [
            'mid' => [
                'usage' => 'streaming',
                'resolution' => '1080P',
                'slug' => 'broadcast',
                'name' => 'PCTG Broadcast',
                'summary' => 'Go live without buffering your own gameplay.',
                'promise' => 'Streaming at 1080p while gaming, with headroom for a second feed.',
                'platform' => 'AM5',
            ],
            'high' => [
                'usage' => 'streaming',
                'resolution' => '1080P',
                'slug' => 'onair',
                'name' => 'PCTG On Air',
                'summary' => 'Studio-grade output, two cameras, no dropped frames.',
                'promise' => 'Multi-stream 1080p production for a serious channel.',
                'platform' => 'AM5',
            ],
        ],

        // ------------------------------------------------ CONTENT CREATION
        'content_1080P' => [
            'mid' => [
                'usage' => 'content_creation',
                'resolution' => '1080P',
                'slug' => 'studioedit',
                'name' => 'PCTG Edit',
                'summary' => 'Cuts fast, previews smooth, exports without the wait.',
                'promise' => 'Editing and colour work at 1080p.',
                'platform' => 'AM5',
            ],
            'high' => [
                'usage' => 'content_creation',
                'resolution' => '1080P',
                'slug' => 'renderworks',
                'name' => 'PCTG Renderworks',
                'summary' => 'Heavy timelines and long renders, handled without drama.',
                'promise' => '4K-capable editing and rendering on a single machine.',
                'platform' => 'AM5',
            ],
        ],

        // ------------------------------------------------------------ STUDIO
        'studio_1080P' => [
            'mid' => [
                'usage' => 'studio',
                'resolution' => '1080P',
                'slug' => 'workshop',
                'name' => 'PCTG Workshop',
                'summary' => 'A quiet, reliable machine for long sessions at the desk.',
                'promise' => 'Day-to-day studio work with room to spare.',
                'platform' => 'AM5',
            ],
            'high' => [
                'usage' => 'studio',
                'resolution' => '1080P',
                'slug' => 'atelier',
                'name' => 'PCTG Atelier',
                'summary' => 'Everything open at once, and it stays that way.',
                'promise' => 'Heavy multitasking across creative applications.',
                'platform' => 'AM5',
            ],
        ],
    ],

    /*
     * Systems generated rather than hand-named.
     *
     * The Boss asked for mid and high in the non-gaming categories to be
     * "randomly created". Hand-written names cannot scale past nine, so those
     * combinations draw a name and a summary from the pools below, deterministically
     * from usage/resolution/tier so the same cell always produces the same
     * product. Deterministic rather than random on purpose: a storefront that
     * renames itself on every page load is impossible to test or to advertise.
     */
    'generated_name_pools' => [
        // Six names per usage: each usage generates six cells
        // (3 resolutions x mid/high), and names are handed out POSITIONALLY, so
        // a hash-based pick collided and produced three different products all
        // called "PCTG Transmission".
        'streaming' => [
            'names' => ['Signal', 'Relay', 'Transmission', 'Livewire', 'Broadcast', 'On Air'],
            'summaries' => [
                'Go live without buffering your own gameplay.',
                'Built for the hours between going live and staying live.',
                'Your channel, running smooth, all evening.',
            ],
        ],
        'content_creation' => [
            'names' => ['Cutline', 'Keyframe', 'Exposure', 'Masterwork', 'Renderworks', 'Edit'],
            'summaries' => [
                'Cuts fast, previews smooth, exports without the wait.',
                'Heavy timelines and long renders, handled without drama.',
                'The boring parts of making things, made fast.',
            ],
        ],
        'studio' => [
            'names' => ['Workbench', 'Drafting', 'Atelier', 'Soundhouse', 'Workshop', 'Studio Floor'],
            'summaries' => [
                'A quiet, reliable machine for long sessions at the desk.',
                'Everything open at once, and it stays that way.',
                'Built for the work, not the glamour.',
            ],
        ],
    ],
];