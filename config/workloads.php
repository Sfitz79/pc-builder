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
     * Workloads.
     *
     * Keys are the canonical slug. Anything in `aliases` resolves to the key,
     * so 'home business', 'home-business', 'home_business' and 'small office'
     * all land on the same workload rather than being coerced to gaming.
     *
     * weights are 0-100 and feed the deterministic scoring in
     * AIRecommendationService when Gemini is not configured. They are a floor,
     * not a suggestion: a workload that says `minRamGb => 32` will not be
     * quoted a 16GB kit however cheap it is.
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