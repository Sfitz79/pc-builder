<?php

/**
 * Workloads and modes — the single source of truth for WHAT A BUILD IS FOR.
 *
 * WHY THIS EXISTS
 * ---------------
 * The configurator only understood four purposes ('gaming', 'streaming',
 * 'creation', 'ai') and GeminiService::sanitizePurpose() silently coerced
 * anything else to 'gaming'. So asking for a workstation, a server or a NAS
 * did not produce a "not supported" answer - it quietly produced a GAMING
 * machine, which is the worst possible failure: a confident, wrong answer.
 *
 * Two concepts, deliberately separate:
 *
 *   SEGMENT   the mode the whole page is in: 'gaming' or 'business'.
 *             A segment decides tone, resolution relevance and which parts
 *             matter. It is NOT a workload.
 *
 *   WORKLOAD  the specific job inside a segment. Two workloads can share a
 *             segment but still weight hardware differently - 'rendering' and
 *             'nas' are both business, but one is GPU/VRAM bound and the other
 *             is bay-count and throughput bound.
 *
 * Conflating them was the original bug, so they are separate keys here.
 *
 * EVERY WORKLOAD CARRIES ITS OWN HARDWARE GUIDANCE. The AI is told what the
 * workload is actually constrained by instead of guessing from the label,
 * which is what let a 4GB DDR4 kit through a 32GB workstation request in the
 * first place.
 *
 * @see \App\Services\GeminiService::sanitizePurpose()
 * @see \App\Services\AIRecommendationService
 */
return [

    /*
     * Modes. The builder asks which mode it is in; that choice sets copy,
     * resolution handling and which weights apply.
     */
    'segments' => [
        'gaming' => [
            'label' => 'Gaming',
            'blurb' => 'Frame rate, latency and graphics settings.',
            // Gaming is the only segment where a display resolution is a
            // meaningful constraint. Everywhere else "1440P" is noise, and
            // treating it as one is why business builds were being asked to
            // satisfy a resolution band they have no relationship to.
            'resolutionRelevant' => true,
        ],
        'business' => [
            'label' => 'Business & Pro',
            'blurb' => 'Throughput, reliability, quietness and uptime.',
            'resolutionRelevant' => false,
        ],
    ],

    /*
     * The workload the PCTG Business builder opens on.
     *
     * Declared explicitly rather than taken as "the first business key in the
     * array" so that reordering or inserting a workload cannot silently change
     * what a business customer is quoted by default.
     */
    'businessDefaultWorkload' => 'studio',

    /*
     * What a business build fundamentally REQUIRES, as capability keys rather
     * than part names, and what we actually stock against each one.
     *
     * WHY THIS EXISTS
     * ---------------
     * Measured 2026-10-15 against production (2,703 active components), read
     * through the fields the picker and the consumer actually use - the product
     * name AND the JSON spec keys, because a probe on the name alone has
     * already been wrong once in this project:
     *
     *     server_cpu            Xeon / EPYC / Threadripper            0 rows
     *     workstation_chipset   W680 / C266 / WRX90 / TRX50           0 rows
     *     ecc_memory            ECC or RDIMM                         0 rows
     *     enterprise_drive      IronWolf / WD Red / Exos / SAS       0 rows
     *     pro_gpu               Radeon Pro / RTX A / Quadro           1 row
     *     multi_drive_bay       a case with usable drive bays         see gate
     *
     * So PCTG Business cannot currently build a server, a NAS or a render node.
     * Before this key existed it could still ASK for one, and answered with an
     * RTX 5070 in an RGB NZXT case - the exact "gaming build with a business
     * sticker" the copy on /business says we do not do.
     *
     * A workload whose requirements are unmet is not offered as a one-click
     * build. It is offered as a spec conversation instead. That is Rule 6 in
     * AGENTS.md - unknown must never equal permitted - applied to the
     * configurator instead of to a performance tier.
     *
     * The picker itself is not the bottleneck and is not being blamed here: the
     * shares work, and the workload weights genuinely move the budget. There
     * is simply nothing in the catalogue for the money to move towards.
     *
     * @see \App\Services\BusinessCapabilityGate
     */
    'capabilities' => [
        'server_cpu' => [
            'label' => 'a server or workstation processor (Xeon, Threadripper or EPYC)',
        ],
        'workstation_chipset' => [
            'label' => 'a workstation or server chipset (W680, C266, WRX90 or TRX50)',
        ],
        'ecc_memory' => [
            'label' => 'ECC registered memory',
        ],
        'enterprise_drive' => [
            'label' => 'enterprise or NAS-rated drives',
        ],
        'pro_gpu' => [
            'label' => 'a professional graphics card',
        ],
        'pro_gpu_high' => [
            'label' => 'a professional graphics card with real memory behind it (16GB+ VRAM)',
        ],
        'quiet_cooling' => [
            'label' => 'quiet air cooling',
        ],
        'multi_drive_bay' => [
            'label' => 'a case with enough drive bays for the storage',
        ],
    ],

    /*
     * Workloads.
     *
     * Keys are the canonical slug. Anything in `aliases` resolves to the key,
     * so 'home business', 'home-business', 'home_business' and 'small office'
     * all land on the same workload rather than being coerced to gaming.
     *
     * `requires` lists capability keys from the block above. An empty list
     * means the catalogue can serve the workload today. A non-empty one is
     * checked against the live catalogue on every page load (cached), and an
     * unmet requirement moves the workload off the build button.
     *
     * weights are 0-100 and are applied to the scored pools by
     * AIRecommendationService. They are a floor, not a suggestion: a NAS build
     * declares gpu => 5, so the budget cannot drift onto a graphics card.
     *
     * HOW THEY REACH THE PICKER, precisely, because this was previously a false
     * claim in this docblock: they are applied in TWO ways, not one.
     *
     *   1. With Gemini configured, they are stated in the prompt (GeminiService)
     *      and Gemini returns its own per-category multipliers, which are applied
     *      by AIRecommendationService::applyWeights().
     *   2. WITHOUT Gemini - scoreWeights() returns null and nothing was applied
     *      at all, so a NAS build could still have been quoted a gaming GPU.
     *      AIRecommendationService::workloadShares() now normalises these into
     *      per-category budget shares and applies them on that path too.
     *
     * `minRamGb` needs no equivalent wiring: the deterministic pool filter
     * already refuses anything under AIRecommendationService::MIN_RAM_GB (32),
     * which is at or above every floor declared here.
     */
    'workloads' => [

        // ---------------------------------------------------------- GAMING
        'gaming' => [
            'label' => 'Gaming',
            'segment' => 'gaming',
            'aliases' => ['game', 'games', 'fps', 'esports', 'competitive'],
            'blurb' => 'Frame rate in competitive shooters and open worlds.',
            'guidance' => 'Prioritise GPU then CPU. High refresh rate is the goal, '
                . 'so favour high frame rates over high resolution.',
            'weights' => ['gpu' => 95, 'cpu' => 70, 'ram' => 45, 'storage' => 30, 'psu' => 40],
        ],
        'streaming' => [
            'label' => 'Streaming',
            'segment' => 'gaming',
            'aliases' => ['stream', 'twitch', 'youtube', 'obs', 'broadcast'],
            'blurb' => 'Gameplay and a second encoded feed at once.',
            'guidance' => 'Needs an NVENC or equivalent encoder AND enough headroom to '
                . 'encode without dropping gameplay frames. Never quote an encode-limited card.',
            'weights' => ['gpu' => 90, 'cpu' => 85, 'ram' => 60, 'storage' => 35, 'psu' => 40],
        ],
        'creation' => [
            'label' => 'Content Creation',
            'segment' => 'gaming',
            'aliases' => ['content', 'editing', 'youtube-content', 'photo', 'photoshop'],
            'blurb' => 'Editing, colour grading and export.',
            'guidance' => 'Favour many fast cores and a large, fast scratch drive. '
                . 'Storage speed is felt far more here than in gaming.',
            'weights' => ['gpu' => 70, 'cpu' => 90, 'ram' => 80, 'storage' => 75, 'psu' => 40],
        ],

        // -------------------------------------------------------- BUSINESS
        'studio' => [
            'label' => 'Creative Studio',
            'segment' => 'business',
            'aliases' => ['creative', 'studio-workstation', 'design', 'davinci', 'premiere', 'after effects'],
            'blurb' => 'Editing, VFX and colour in a professional suite.',
            'guidance' => 'Professional media workloads want sustained load and stable '
                . 'thermals over peak boost. Budget for memory first: 32GB is a floor, '
                . 'not a target, and a colourist will need far more.',
            'weights' => ['gpu' => 88, 'cpu' => 85, 'ram' => 95, 'storage' => 70, 'psu' => 45],
            'requires' => ['quiet_cooling'],
            'minRamGb' => 32,
        ],
        'rendering' => [
            'label' => 'Pro Rendering',
            'segment' => 'business',
            'aliases' => ['render', 'render-farm', 'pro-rendering', 'cgi', 'vfx-render', 'bake'],
            'blurb' => 'Frames, simulations and batch output.',
            'guidance' => 'This is VRAM and sustained-throughput bound. A card that is '
                . 'fast in games can be useless here. Prefer professional multi-GPU, '
                . 'and never spec a single consumer card for a render node.',
            'weights' => ['gpu' => 97, 'cpu' => 85, 'ram' => 90, 'storage' => 65, 'psu' => 60],
            'requires' => ['pro_gpu'],
            'minRamGb' => 32,
        ],
        'enterprise' => [
            'label' => 'Enterprise Servers',
            'segment' => 'business',
            'aliases' => ['enterprise-server', 'server-rack', 'datacentre', 'datacenter', 'onprem', 'on-prem', 'virtualisation', 'virtualization'],
            'blurb' => 'On-site compute, virtualisation and shared storage.',
            'guidance' => 'SERVICEABILITY AND UPTIME, NOT FPS. A server must be '
                . 'quiet enough for an occupied room, have redundant power where '
                . 'available, and be specified for RAM capacity and drive bays rather '
                . 'than graphics. Do not put a gaming GPU in an enterprise build.',
            'weights' => ['gpu' => 20, 'cpu' => 92, 'ram' => 96, 'storage' => 92, 'psu' => 75],
            'requires' => ['server_cpu', 'workstation_chipset', 'ecc_memory'],
            'minRamGb' => 32,
        ],
        'server' => [
            'label' => 'Servers',
            'segment' => 'business',
            'aliases' => ['server', 'rack', 'rackserver', 'homelab', 'home-lab', 'proxmox'],
            'blurb' => 'Rack or tower servers for compute and virtualisation.',
            'guidance' => 'Core count and memory channels drive VM density. Thermal and '
                . 'acoustic design matter because these run for years, unattended. '
                . 'ECC RAM is worth calling out where the platform supports it.',
            'weights' => ['gpu' => 18, 'cpu' => 95, 'ram' => 94, 'storage' => 88, 'psu' => 80],
            'requires' => ['server_cpu', 'ecc_memory', 'quiet_cooling'],
            'minRamGb' => 32,
        ],
        'nas' => [
            'label' => 'NAS & Storage',
            'segment' => 'business',
            'aliases' => ['nas', 'storage', 'network-storage', 'synology', 'backup', 'file-server'],
            'blurb' => 'Network-attached storage, backup and shared files.',
            'guidance' => 'DRIVE BAYS AND NETWORK THROUGHPUT, NOT GRAPHICS. Almost no '
                . 'GPU value is wanted. What decides this build is bay count, drive '
                . 'interface, RAID support and sustained write throughput. Do not let '
                . 'the generic scoring spend the budget on a graphics card.',
            'weights' => ['gpu' => 5, 'cpu' => 70, 'ram' => 75, 'storage' => 99, 'psu' => 70],
            'requires' => ['enterprise_drive', 'multi_drive_bay', 'ecc_memory'],
            'minRamGb' => 8,
        ],
        'home-business' => [
            'label' => 'Home Business',
            'segment' => 'business',
            'aliases' => ['home business', 'home_business', 'home-office', 'small office', 'self employed', 'self-employed', 'soho', 'freelance'],
            'blurb' => 'A dependable quiet machine for working from home.',
            'guidance' => 'Quietness under sustained office load is the point, not peak '
                . 'speed. A cooling solution that is audible in a living room disqualifies '
                . 'the build. Favour a modest, efficient, serviceable box over a hot one.',
            'weights' => ['gpu' => 35, 'cpu' => 80, 'ram' => 72, 'storage' => 65, 'psu' => 45],
            'requires' => ['quiet_cooling'],
            'minRamGb' => 16,
        ],
        'ai' => [
            'label' => 'AI & Data',
            'segment' => 'business',
            'aliases' => ['ai', 'llm', 'local-llm', 'inference', 'machine-learning', 'ml', 'data'],
            'blurb' => 'Local models, inference and large datasets.',
            'guidance' => 'VRAM capacity is the hard ceiling - a model that does not fit '
                . 'does not run slowly, it does not run. Memory and storage bandwidth '
                . 'set the ceiling far more than raw compute does.',
            'weights' => ['gpu' => 93, 'cpu' => 80, 'ram' => 95, 'storage' => 80, 'psu' => 65],
            'minRamGb' => 32,
        ],

        /*
         * LIVE PRODUCTION - the business half of "streaming".
         *
         * WHY A SECOND STREAMING WORKLOAD RATHER THAN MOVING THE EXISTING ONE
         * ------------------------------------------------------------------
         * There were two different products sharing one key. `streaming` is a
         * GAMING workload - "gameplay and a second encoded feed at once", it
         * needs an NVENC-class card and it cares about frame rate, so it is
         * correct where it is. /business sells the other one: sustained encode
         * of a live production feed, where "clean output matters more than frame
         * rate" and the constraint is encode headroom, not frames.
         *
         * They disagree on the hardware. Gaming streaming wants a fast consumer
         * card and does not mind a 1440p display band; production encoding wants
         * sustained throughput and has no relationship to a resolution band at
         * all. Folding them together would have meant one of the two quietly
         * got the wrong answer, which is the failure this whole config exists
         * to prevent.
         *
         * The /business Streaming pillar previously deep-linked to NOTHING -
         * `streaming` is a gaming slug, so the fail-closed resolver dropped it
         * to the default workload. It now has a real key to point at.
         */
        'live-production' => [
            'label' => 'Live Production',
            'segment' => 'business',
            'aliases' => [
                'live production', 'live-production', 'livestream', 'live stream',
                'broadcast', 'live broadcast', 'streaming production', 'encoder',
                'encoder box', 'rtmp', 'srt', 'multicam', 'switcher', 'obs studio',
                'production streaming',
            ],
            'blurb' => 'Multi-camera live output, running unattended for hours.',
            'guidance' => 'SUSTAINED ENCODE, NOT FRAME RATE. The encoder runs for '
                . 'hours without a frame drop, so favour a card with hardware '
                . 'encoding headroom and a CPU that will not throttle, and prefer '
                . 'quiet, reliable cooling over anything that sounds busy on air. '
                . 'Never quote an encode-limited card.',
            'weights' => ['gpu' => 88, 'cpu' => 88, 'ram' => 70, 'storage' => 60, 'psu' => 50],
            'requires' => ['quiet_cooling'],
            'minRamGb' => 32,
        ],
    ],


    /*
     * What a builder must never do when the mode is business.
     *
     * These are hard refusals rather than preferences. A gaming-first answer to a
     * server request is not a worse answer, it is a wrong one, and Rule 6 in
     * AGENTS.md says unknown must never equal permitted.
     */
    'businessGuardrails' => [
        'ignoreResolutionBand' => true,
        'forbidGamingFirstSelection' => ['nas', 'enterprise', 'server'],
        'requireQuietConsideration' => ['home-business', 'studio', 'enterprise'],
        'minRamGb' => 16,
    ],
];