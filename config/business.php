<?php

/**
 * PCTG BUSINESS — the SME range.
 *
 * WHAT THIS IS
 * ------------
 * A three-level taxonomy: BUSINESS SIZE -> JOB TYPE -> SYSTEM TYPE, where each
 * system type resolves to a workload (what to build for), a set of capabilities
 * (whether we can build it) and an indicative band (whether the money fits).
 *
 * The earlier shape was a flat list of seven workloads - "studio", "rendering",
 * "nas". A sole trader and a hospital imaging department both got offered
 * "Creative Studio" with no indication that the two want very different
 * machines, at very different prices. Businesses do not buy by software
 * workload; they buy by what they do all day and how many people are doing it.
 *
 * WHY IT IS HERE AND NOT IN workloads.php
 * ---------------------------------------
 * config/workloads.php answers "how do we weight the BUDGET". This file answers
 * "what does this customer actually want, and can we serve them". Two different
 * questions, kept apart, and the workloads remain the single source of truth for
 * anything that touches money - so adding a job type here can never change how a
 * gaming build is priced.
 *
 * WHERE THE HARDWARE NUMBERS COME FROM
 * ------------------------------------
 * Not invented. Grounded in UK SME guidance checked 2026-10-15:
 *
 *   - nibusinessinfo.co.uk (the government's small-business guidance):
 *     multi-core at 2GHz+ covers most business functions; 500GB is a typical
 *     office minimum; SSD beats spinning disk for every office use; desktops are
 *     replaced on a 3-4 year cycle and servers on a 5 year one.
 *   - Framework IT (May 2026): i5 / Ryzen 5 is the floor, 16GB minimum,
 *     32GB for power users. TPM 2.0 and Intel vPro / AMD Pro matter for
 *     manageability, which is why `business_manageable` is a capability here.
 *   - JK Consulting (Feb 2026): 16GB minimum, 32GB power users, 64GB+ for
 *     workstations and AI; NVMe standard and 512GB minimum; workstations need
 *     professional GPUs and high core-count CPUs with certified drivers.
 *   - Role-based segmentation is the consensus across all three: equip most
 *     people to a standard spec and upgrade only the roles that need it. The
 *     system types below are therefore job types, not per-company builds.
 *
 * @see \App\Services\BusinessCapabilityGate
 * @see \App\Services\BusinessSystemType
 */
return [

    /*
     * Level 1: how big the business is. This drives the price expectation and
     * the default system type, and it is what a buyer recognises themselves by.
     */
    'sizes' => [
        'home-office' => [
            'label' => 'Home Office',
            'blurb' => 'One person, working from home. The machine has to be quiet enough to live with.',
            'headcount' => 'Just you',
        ],
        'small-office' => [
            'label' => 'Small Office',
            'blurb' => 'A few people sharing an office, running Microsoft 365 and back-office systems.',
            'headcount' => '2 - 10 people',
        ],
        'retail' => [
            'label' => 'Retail & Front of House',
            'blurb' => 'A counter, a till and a back office. Runs all day, in a room customers are standing in.',
            'headcount' => '1 - 20 sites',
        ],
        'workstation' => [
            'label' => 'Professional Workstation',
            'blurb' => 'One specialist doing CAD, editing, medical imaging or engineering work.',
            'headcount' => 'Per person',
        ],
        'server' => [
            'label' => 'Server & Virtualisation',
            'blurb' => 'On-site compute, virtual machines or shared services for a whole team.',
            'headcount' => 'Shared',
        ],
        'storage' => [
            'label' => 'Storage & Backup',
            'blurb' => 'Network storage and backup - the thing standing between you and losing the data.',
            'headcount' => 'Shared',
        ],
    ],

    /*
     * Level 2 and 3: job type -> system type.
     *
     *   workload  the config/workloads.php slug the picker uses
     *   requires  capability keys; an unmet one removes the build button
     *   band      indicative GBP range for the COMPLETE system, not a quote.
     *             Shown as guidance only - never as a price we will sell at,
     *             because a live quote is priced from the catalogue.
     *   minRamGb / minStorageGb  the floor this job type actually needs.
     *
     * `wants` is the honest warning when the floor is above what our
     * component builder will produce. AIRecommendationService::MIN_RAM_GB is 32,
     * so nothing below that is buildable by the configurator at all.
     */
    'systems' => [

        // ------------------------------------------------------- HOME OFFICE
        'home-office-standard' => [
            'size' => 'home-office',
            'job' => 'General office',
            'label' => 'Home Office Workstation',
            'blurb' => 'Email, spreadsheets, video calls and Microsoft 365, quietly, all day.',
            'workload' => 'home-business',
            'requires' => ['quiet_cooling'],
            'band' => [700, 1200],
            'minRamGb' => 16,
            'minStorageGb' => 512,
        ],
        'home-office-creative' => [
            'size' => 'home-office',
            'job' => 'Light creative',
            'label' => 'Home Office Plus',
            'blurb' => 'The same quiet machine, with the memory and storage for photo and light editing.',
            'workload' => 'studio',
            'requires' => ['quiet_cooling'],
            'band' => [1200, 1800],
            'minRamGb' => 32,
            'minStorageGb' => 1000,
        ],

        // ------------------------------------------------------ SMALL OFFICE
        'small-office-standard' => [
            'size' => 'small-office',
            'job' => 'General office',
            'label' => 'Small Office Desktop',
            'blurb' => 'The standard desk machine: accounts, CRM, document work and calls.',
            'workload' => 'home-business',
            'requires' => ['quiet_cooling', 'business_manageable'],
            'band' => [900, 1500],
            'minRamGb' => 16,
            'minStorageGb' => 512,
        ],
        'small-office-power' => [
            'size' => 'small-office',
            'job' => 'Power user',
            'label' => 'Small Office Power User',
            'blurb' => 'For the analyst or bookkeeper with twenty browser tabs and a million-row spreadsheet open.',
            'workload' => 'home-business',
            'requires' => ['quiet_cooling'],
            'band' => [1400, 2200],
            'minRamGb' => 32,
            'minStorageGb' => 1000,
        ],

        // ------------------------------------------------------------ RETAIL
        'retail-counter' => [
            'size' => 'retail',
            'job' => 'Till & counter',
            'label' => 'Retail Counter System',
            'blurb' => 'Built for a till, a card reader and a customer standing there. Compact, quiet, on all day.',
            'workload' => 'home-business',
            'requires' => ['quiet_cooling', 'compact_chassis'],
            'band' => [900, 1600],
            'minRamGb' => 16,
            'minStorageGb' => 512,
        ],
        'retail-backoffice' => [
            'size' => 'retail',
            'job' => 'Back office',
            'label' => 'Retail Back Office System',
            'blurb' => 'Stock, rotas and multi-screen admin for the office behind the counter.',
            'workload' => 'home-business',
            'requires' => ['quiet_cooling'],
            'band' => [1100, 1900],
            'minRamGb' => 32,
            'minStorageGb' => 1000,
        ],

        // ------------------------------------------------------- WORKSTATION
        'workstation-creative' => [
            'size' => 'workstation',
            'job' => 'Creative & media',
            'label' => 'Creative Workstation',
            'blurb' => 'Editing, colour and VFX. Memory and sustained load before peak boost.',
            'workload' => 'studio',
            'requires' => ['quiet_cooling'],
            'band' => [1500, 3500],
            'minRamGb' => 32,
            'minStorageGb' => 1000,
        ],
        'workstation-render' => [
            'size' => 'workstation',
            'job' => 'Rendering & VFX',
            'label' => 'Render Node',
            'blurb' => 'Frames, simulations and batch output. Professional multi-GPU, never a single consumer card.',
            'workload' => 'rendering',
            'requires' => ['pro_gpu_high'],
            'sourcing' => 'desktops',
            'band' => [3000, 8000],
            'minRamGb' => 64,
            'minStorageGb' => 2000,
        ],
        'workstation-engineering' => [
            'size' => 'workstation',
            'job' => 'CAD & engineering',
            'label' => 'CAD Workstation',
            'blurb' => 'SolidWorks, Revit and Inventor. A certified professional driver, not a gaming card.',
            'workload' => 'studio',
            'requires' => ['pro_gpu'],
            'band' => [2200, 5500],
            'minRamGb' => 64,
            'minStorageGb' => 1000,
        ],
        'workstation-medical' => [
            'size' => 'workstation',
            'job' => 'Medical imaging',
            'label' => 'Diagnostic Imaging Workstation',
            'blurb' => 'PACS reading. DICOM volumes are memory-hungry and the read has to be instant.',
            'workload' => 'studio',
            'requires' => ['pro_gpu_high'],
            'sourcing' => 'desktops',
            'band' => [2500, 6000],
            'minRamGb' => 64,
            'minStorageGb' => 2000,
        ],
        'workstation-development' => [
            'size' => 'workstation',
            'job' => 'Development & AI',
            'label' => 'Development Workstation',
            'blurb' => 'Local builds, containers and local models. Memory and storage bandwidth set the ceiling.',
            'workload' => 'ai',
            'requires' => [],
            'band' => [1800, 6000],
            'minRamGb' => 64,
            'minStorageGb' => 2000,
        ],

        // ------------------------------------------------------------ SERVER
        'server-fileshare' => [
            'size' => 'server',
            'job' => 'File & print server',
            'label' => 'Small File Server',
            'blurb' => 'Shared drives, shared printers, permissions. The heart of a small network.',
            'workload' => 'server',
            'requires' => ['server_cpu', 'ecc_memory', 'quiet_cooling'],
            'sourcing' => 'servers',
            'band' => [1400, 3000],
            'minRamGb' => 32,
            'minStorageGb' => 1000,
        ],
        'server-virtualisation' => [
            'size' => 'server',
            'job' => 'Virtualisation',
            'label' => 'Virtualisation Host',
            'blurb' => 'Running the servers you would otherwise pay a hosting company for.',
            'workload' => 'enterprise',
            'requires' => ['server_cpu', 'workstation_chipset', 'ecc_memory'],
            'sourcing' => 'servers',
            'band' => [2500, 8000],
            'minRamGb' => 64,
            'minStorageGb' => 2000,
        ],

        // ----------------------------------------------------------- STORAGE
        'storage-backup' => [
            'size' => 'storage',
            'job' => 'Backup & archive',
            'label' => 'Network Backup Appliance',
            'blurb' => 'The thing you find out you needed, on the day you find out you needed it.',
            'workload' => 'nas',
            'requires' => ['enterprise_drive', 'multi_drive_bay', 'ecc_memory'],
            'sourcing' => 'servers',
            'band' => [800, 4000],
            'minRamGb' => 8,
            'minStorageGb' => 8000,
        ],
        'storage-shared' => [
            'size' => 'storage',
            'job' => 'Shared team storage',
            'label' => 'Shared Storage Server',
            'blurb' => 'Media libraries and project files everyone needs to reach without emailing them.',
            'workload' => 'nas',
            'requires' => ['enterprise_drive', 'multi_drive_bay'],
            'sourcing' => 'servers',
            'band' => [1200, 5000],
            'minRamGb' => 16,
            'minStorageGb' => 8000,
        ],
    ],

    /*
     * Where a system type we cannot build is actually sold.
     *
     * WHY THIS EXISTS
     * ---------------
     * Six of the fifteen system types cannot be assembled from our catalogue -
     * no server processors, no ECC memory, no enterprise drives, no multi-bay
     * cases, one entry-level professional GPU. BusinessCapabilityGate removes
     * the build button and names the missing part, which is right. But it still
     * leaves the buyer with nothing, and a customer who told us what they need
     * and then hit a dead end is worse than one who never asked.
     *
     * So: signpost. Not a referral programme, not a shop, not a comparison we
     * have measured. Plain links, no affiliate, no price quoted on their behalf.
     *
     * EVERY URL HERE WAS CHECKED RESOLVING before being written down, on
     * 2026-10-15. Four candidates were rejected for exactly that reason: HP's
     * root /gb-en/business closed the connection, ebuyer timed out, and Currys
     * and Overclockers both returned 403 to an automated request - which proves
     * the host serves bots differently but does NOT prove the specific path
     * exists, and a broken signpost is worse than a missing one. Those are
     * worth adding, but only once someone has opened them in a browser.
     *
     * No prices are shown. Quoting a figure off someone else's website is a
     * claim about a product we neither stock nor have verified today, and the
     * price would be wrong within a week. The buyer's own quote at checkout is
     * the only number on this site we will stand behind.
     */
    'sourcing' => [
        'desktops' => [
            'blurb' => 'Business desktop lines, sold and warranted by the manufacturer.',
            'links' => [
                [
                    'name' => 'Dell Pro desktops',
                    'url' => 'https://www.dell.com/en-gb/shop/business/c/desktops',
                ],
                [
                    'name' => 'HP Pro and Z workstations',
                    'url' => 'https://www.hp.com/gb-en/shop/categories/small-business-pcs',
                ],
                [
                    'name' => 'Scan Business',
                    'url' => 'https://www.scan.co.uk/business',
                ],
            ],
        ],
        'servers' => [
            'blurb' => 'Rack and tower servers, with the manufacturer warranty and support options attached.',
            'links' => [
                [
                    'name' => 'Dell servers',
                    'url' => 'https://www.dell.com/en-gb/shop/servers/c/servers',
                ],
                [
                    'name' => 'Scan Business',
                    'url' => 'https://www.scan.co.uk/business',
                ],
            ],
        ],
    ],

    /*
     * Capabilities referenced above but not checked against parts.
     *
     * `business_manageable` and `compact_chassis` are deliberately NOT
     * catalogue capabilities. They are properties of the CHASSIS and the
     * firmware - vPro/AMDNA remote management, TPM 2.0, and a small footprint -
     * and the component catalogue cannot answer either. Asserting them from
     * component specs would be inventing a capability claim, so they are
     * declared as advisory and surfaced as a note rather than used to remove a
     * build button.
     */
    'advisory' => [
        'business_manageable' => 'Remote management (Intel vPro / AMD PRO) and TPM 2.0. Ask us and we will confirm the exact board.',
        'compact_chassis' => 'A small-form-factor or mini chassis that fits behind a counter or under a desk.',
    ],
];
