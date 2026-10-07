<?php

namespace App\Services;

use App\Models\AiRecommendation;
use App\Models\Category;
use App\Models\Component;

class AIRecommendationService
{
    public function __construct(protected GeminiService $gemini)
    {
        $this->pricing = app(BuildPricingService::class);
        $this->priceIntegrity = app(PriceIntegrityService::class);
    }

    /**
     * Hidden-margin pricing engine (complete price = parts + margin).
     */
    protected BuildPricingService $pricing;

    /**
     * Price integrity gate: strips corrupt (bundle-priced / scraped-wrong)
     * rows out of every build pool and reports per-build price freshness.
     */
    protected PriceIntegrityService $priceIntegrity;

    /**
     * Categories that must all be present for a build to be complete.
     *
     * @var list<string>
     */
    protected array $requiredCategories = Component::REQUIRED_CATEGORIES;

    /**
     * Memoised measured entry prices, keyed by upper-cased resolution.
     *
     * @var array<string, array{price: float, rounded: float, cpu: string|null, gpu: string|null, complete: bool}|null>
     */
    protected array $entryPriceCache = [];

    /**
     * PCTG spec floors. These are pass/fail minimums, not preferences â€” see
     * `passesSpecFloor()`.
     */
    public const MIN_RAM_GB = 32;
    public const MIN_STORAGE_GB = 500;

    /**
     * All-in price below which we cannot build a dedicated-GPU PCTG-standard
     * machine, so the integrated-graphics system becomes the honest entry
     * product.
     *
     * Measured from the live catalogue 2026-09-27: the cheapest complete
     * dedicated-GPU machine is GBP 948.44 all-in, and the entry APU machine is
     * GBP 786.08. The constant sits above the dGPU floor (so anyone who can
     * afford a graphics card is never handed a GPU-less box) and at a round
     * figure, which also gives the customer a clean rule: under GBP 1,000 you
     * get the entry machine, from GBP 1,000 you get a dedicated graphics card.
     *
     * It was 950.00 when first added, which was correct against a GBP 906.62
     * dGPU floor. Rejecting the DDR4-2133 memory kit (see isModernMemory)
     * pushed that floor to GBP 948.44 and left only GBP 1.56 of headroom, so
     * the constant was recalibrated here rather than left to drift. Any change
     * to the spec floors moves these two numbers and this threshold must be
     * re-measured with the floor probe.
     *
     * Re-measured 2026-09-28 after two changes: delivery is now folded into the
     * single all-in system price, and unverified parts were removed from the
     * pools. Measured entry prices on the live catalogue are now APU GBP
     * 1,043.58 and dGPU GBP 1,247.32 (all-in, delivery included) - against the
     * old GBP 785.85 / GBP 907 pair, which excluded delivery. The APU entry sits
     * only 4.4% over a GBP 1,000 ask and is therefore still sellable at GBP
     * 1,000; the dGPU entry needs GBP 1,250. The threshold is set to the dGPU
     * entry so customers below it get the honest APU box instead of being
     * quoted a GBP 1,247 graphics machine they did not ask for.
     */
    /**
     * SUPERSEDED (boss directive 2026-09-28) - kept only so old references
     * resolve, and because the number is still printed by regression-probe as
     * a historical marker.
     *
     * This used to be the price below which we offered the GPU-less APU
     * machine. The APU lane is now retired entirely: the entry processor we are
     * required to quote (Ryzen 5 4500 / Core i5-12400) has no integrated
     * graphics, so every machine we sell carries a discrete card and there is
     * no second lane to choose between.
     *
     * The floor that actually governs pricing is now per-resolution and
     * MEASURED rather than typed - see `workableBands()` and `entryPriceFor()`:
     *
     *   1080p  GBP 1,350    1440p  GBP 1,530    4K  GBP 1,960
     *
     * @see workableBands() for the live figures the storefront uses
     */
    public const MIN_DGPU_ENTRY = 1250.0;

    /**
     * The lowest processor we will put in a recommended build (boss directive
     * 2026-09-28): an AMD Ryzen 5 4500 or an Intel Core i5-12400, or better.
     *
     * Both are six-core parts. Neither has integrated graphics, which is
     * exactly why the GPU-less lane is retired - every build we recommend now
     * carries a discrete graphics card. This constant is the enforcement
     * point for that sentence, so the rule cannot be quietly weakened by a
     * future pool change.
     *
     * The APU-class chips this replaces (Ryzen 3 3200G at GBP 49.02 and the
     * 2-core Pentium/i3 parts below it) are excluded outright. The 3200G was
     * the old entry product at GBP 1,043.58 and is no longer quotable.
     */
    public const ENTRY_CPU_FLOOR_CORES = 6;


    /**
     * Minimum processor class for a build that carries a discrete graphics card.
     *
     * Found 2026-09-28 by measuring the live ladder, not by inspection. A
     * GBP 1,300 1440p request was answered with a 4-core AMD Ryzen 3 3200G
     * behind a discrete card â€” a legal build, but a Haswell-era chip in a
     * machine sold on modern hardware. Three separate budget/resolution
     * combinations produced it (1440P at 1,300 and 1,400; 4K at 1,250, 1,300
     * and 1,400), because the CPU is chosen FIRST in pickBalancedBuild() and
     * the 30% CPU share was enough to buy a cheap 4-core part before the
     * graphics card and the rest of the machine were funded.
     *
     * Two things are wrong with that pairing, and only one of them is about
     * cores:
     *
     * 1. FOUR CORES IS NOT A GAMING PROCESSOR IN 2026. A 4-core chip cannot
     *    feed a modern discrete card, and it caps 1% lows badly - the exact
     *    thing a customer notices and blames on us. This floor is 6 physical
     *    cores, i.e. the Ryzen 5 / Core i5 class, which is the honest
     *    minimum for a machine with a graphics card.
     *
     * 2. THE INTEGRATED GRAPHICS ARE DEAD WEIGHT. The 3200G, 4100, 3400G,
     *    8500G, 8600G, 8700G and 5600G are APUs: their built-in graphics do
     *    nothing once a discrete card is fitted, and they cost more than the
     *    equivalent non-G part in this catalogue (Ryzen 5 5600G at GBP 187.79
     *    against Ryzen 5 5600 at GBP 119.99). Selling one behind a graphics
     *    card is taking the customer's money for a part that does nothing, so
     *    the dGPU lane rejects APUs outright and the money goes into the card.
     *
     * The APU lane is deliberately untouched: the entry machine's whole reason
     * to exist is that its processor drives the display, so the Ryzen 3 3200G
     * is exactly the right part there.
     *
     * Cheapest compliant processor on the live catalogue is the AMD Ryzen 5
     * 4500 at GBP 59.99, so this floor costs about GBP 11 over the rejected
     * Ryzen 3 3200G at GBP 49.02. Cheap insurance against a 5-star review.
     */
    public const MIN_DGPU_CPU_CORES = 6;

    /**
     * Minimum CPU class required to keep a graphics card fed.
     *
     * Added 2026-09-28 after the band re-cut exposed a mismatch the earlier
     * per-resolution rules could not see: a GBP 3,300 4K ask came back as a
     * Ryzen 5 4500 (6 cores, 2020, AM4) behind a GeForce RTX 5080. Both
     * parts cleared their own floors - the CPU had 6 cores and the GPU was a
     * genuine 4K card - so every automated check passed, and it is still the
     * kind of build that produces a one-star review. A 5080 at 4K is
     * GPU-limited only if the processor can feed it; six weak cores cannot,
     * and we would be selling a card the machine cannot use.
     *
     * Tier 5 therefore demands a modern platform AND at least 8 cores. Tier 4
     * (the genuine 4K floor) demands 6, which the entry platform already
     * meets. This is a floor, not a target - it never stops us fitting a
     * faster CPU when the budget allows.
     *
     * @var array<int, array{min_cores: int, modern_platform: bool}>
     */
    public const GPU_CPU_COHERENCE = [
        5 => ['min_cores' => 8, 'modern_platform' => true],
        4 => ['min_cores' => 6, 'modern_platform' => false],
    ];

    /**
     * Budgets below this are built on a 2020-era platform (boss directive
     * 2026-09-28): Ryzen 5000 on AM4, or a 12th-gen Core i5/i7 on LGA1700.
     *
     * Why this exists: at the bottom of the 1080p band the difference
     * between platforms is the difference between a build that lands on
     * budget and one that does not. AM5 boards plus a DDR5 32GB kit cost
     * materially more than an AM4 board plus DDR4 for the same frame rate,
     * so forcing a modern platform under GBP 1,300 just moves the overshoot
     * somewhere else. Ryzen 5 5600 and the Core i5-12400F are still very
     * capable six/ten-core chips, and quoting a GBP 1,000 1080p box that
     * actually adds up is worth more than quoting one on the newest socket
     * that we then have to refuse.
     *
     * This is a VALUE rule, not a performance rule - the tier floors in
     * RESOLUTION_BANDS still guarantee the machine is strong enough for the
     * resolution the customer asked for. The two are independent and are
     * deliberately not merged.
     */
    public const ENTRY_PLATFORM_CEILING = 1300.0;

    /**
     * Can this processor actually feed this graphics card?
     *
     * Checks the GPU_CPU_COHERENCE floor for the card's tier. A card whose
     * tier we do not recognise (tier 0) is left alone rather than blocked, so
     * a new GPU in the catalogue can never make the engine refuse to build.
     */
    public function cpuCanFeedGpu(Component $cpu, ?Component $gpu): bool
    {
        if ($gpu === null) {
            return true;
        }

        $rule = self::GPU_CPU_COHERENCE[$this->gpuPerformanceTier($gpu)] ?? null;

        if ($rule === null) {
            return true;
        }

        $cores = (int) (($cpu->specs ?? [])['cores'] ?? 0);

        if ($cores < $rule['min_cores']) {
            return false;
        }

        if ($rule['modern_platform'] && $this->isEntryPlatformCpu($cpu)) {
            return false;
        }

        return true;
    }

    /**
     * Is this CPU one we are willing to put in a sub-GBP 1,300 build?
     *
     * SOCKET IS THE AUTHORITY, not a list of model names. A name list is a
     * list that rots: the first version matched only Ryzen 5000-series, so a
     * Ryzen 5 4500 was invisible to it, and the £1,700 build the Boss
     * complained about was exactly that part. The follow-up attempt matched
     * 4000/5000 and then a Ryzen 5 2600 walked straight through it. Any EOL
     * socket - AM4, LGA1200, LGA1700 - is an entry platform; AM5 and LGA1851
     * are not. If the socket cannot be determined we fall back to the name
     * test and, failing that, treat it as modern so an unknown part is never
     * silently banned from a build (Rule 6 applies to guarantees we enforce,
     * not to bans we impose).
     *
     * @see MODERN_PLATFORM_SOCKETS
     */
    public function isEntryPlatformCpu(Component $cpu): bool
    {
        $socket = $this->canonicalSocket($cpu);

        if ($socket !== null) {
            return ! in_array($socket, self::MODERN_PLATFORM_SOCKETS, true);
        }

        if ($this->isEntryPlatformCpuName((string) $cpu->name)) {
            return true;
        }

        return false;
    }

    /**
     * Sockets we will put in a build sold at or above ENTRY_PLATFORM_CEILING.
     *
     * AM5 (Zen 3 Zen 4 / Zen 5) and LGA1851 (Core Ultra / Arrow Lake). AM4,
     * LGA1200 and LGA1700 are end-of-life: no new stock of consequence, no
     * platform upgrade path, and PCIe 4.0 boards that cannot carry the
     * graphics cards these machines are sold with.
     */
    public const MODERN_PLATFORM_SOCKETS = ['AM5', 'LGA1851'];

    /**
     * The published PCTG price ladder (boss directive 2026-09-28).
     *
     *   1080p  GBP   800 - GBP 1,500  (revised: measured floor GBP 1,260)
     *   1440p  GBP 1,500 - GBP 2,500  (revised: measured floor GBP 1,530)
     *   4K     GBP 1,850 - GBP 3,500  (revised: measured floor GBP 1,670)
     *
     * This is the single source of truth for where each resolution band
     * starts and stops. It replaced the old one-size-fits-all "every
     * resolution starts around GBP 1,000" assumption, which was the root
     * cause of the 12 ceiling violations found by regression-probe on
     * 2026-09-28: a customer asking GBP 1,250 for 4K was being quoted
     * GBP 1,667, and a customer asking GBP 1,300 for 1440p was quoted
     * GBP 1,368, because nothing told the engine that 4K is not a GBP
     * 1,250 product.
     *
     * 'min' is the honest ENTRY anchor - the price we advertise the band
     * from, and the point below which a quote must be framed as "this is
     * where the band starts" rather than as a build that met the budget.
     * 'max' is the ceiling of the band.
     *
     * Note the deliberate asymmetry: building BELOW 'min' is not a
     * failure, it is a better deal, and we never refuse a customer for
     * offering more than the band ceiling. 'min' exists so that marketing
     * copy, the storefront anchors and the over-budget explanation all
     * quote the same honest starting price instead of three different
     * invented ones.
     *
     * MEASURED, not guessed (boss directive 2026-09-28: advertise only bands
     * we can genuinely deliver).
     *
     * THE BAND IS A QUALITY PROMISE, NOT JUST A PRICE RANGE (boss directive
     * 2026-09-28): every machine in a band must genuinely play AAA games at
     * high settings at that resolution. That is the whole point of the ladder,
     * so 'floor_gpu_tier' is set to the lowest card that actually delivers
     * AAA-high at that resolution, not the lowest card that happens to be in
     * stock.
     *
     *   1080p  tier 2 (RTX 4060 / RX 7600 XT / Arc A750 class and up)
     *   1440p  tier 3 (RTX 5060 Ti / RX 9060 XT / RTX 4060 Ti / RTX 4070)
     *   4K     tier 5 (RTX 5070 Ti / RX 9070 XT / RTX 5080 and up)
     *
     * Two of the original floors were overclaiming and have been raised:
     * 1080p was tier 1, but a 3050 or an Arc A380 is a 1080p medium-settings
     * card, not AAA-high, and quoting one as "AAA at high settings" is the
     * kind of claim that costs a 5-star review. 4K was tier 4, but an
     * RTX 5070 / RX 7700 XT is a 1440p card that stretches to 4K rather than
     * a dependable 4K AAA-high machine.
     *
     * Each 'min' below is produced by entryPriceFor(), which assembles the
     * cheapest COMPLETE machine that clears every floor we hold a build to -
     * consumer graphics card, the band's GPU tier, the VRAM floor, a
     * six-core non-APU processor, socket-matched board, platform-matched
     * memory, PSU wattage and the cooling rule - and returns its real all-in
     * price, rounded UP so the promise is always one we can keep:
     *
     *   1080p  GBP 1,342.37 -> 1,350   Ryzen 5 4500      + Arc A750   (tier 2)
     *   1440p  GBP 1,522.35 -> 1,530   Ryzen 5 4500      + RX 9060 XT (tier 3)
     *   4K     GBP 1,954.58 -> 1,960   Core Ultra 5 225F + RX 9070 GRE (tier 5)
     *
     * Note the 4K processor: a tier-5 card cannot be paired with the six-core
     * entry chip, because GPU_CPU_COHERENCE requires eight cores and a modern
     * platform behind a card of that class. Measuring it honestly is what moved
     * the 4K floor from GBP 1,670 to GBP 1,960 - the earlier number was a
     * machine the engine would have refused to quote.
     *
     * The requested 1080p floor of GBP 800 was never achievable: it assumed a
     * GPU-less Ryzen 3 3200G box, and the entry processor we are required to
     * quote (Ryzen 5 4500 / Core i5-12400) has no integrated graphics, so every
     * machine we sell now needs a discrete card.
     *
     * SUPERSEDED 2026-09-28 by the figures above being re-measured against live
     * Neon for the first time. Every number in this table had been measured
     * against the LOCAL SQLite catalogue, which is a different and smaller
     * dataset. Measured against the real 2,708-row production catalogue:
     *
     *   1080p  GBP 1,430.00 (measured)   1440p  GBP 1,640.00   4K  GBP 2,290.00
     *
     * Two consequences, both of which are why the table is now contiguous:
     *
     * 1. The old floors UNDER-PROMISED. Advertising "1080p from GBP 1,350"
     *    when the cheapest machine the engine can actually quote is GBP 1,430
     *    means a customer who types GBP 1,350-1,429 is quoting a budget we
     *    cannot fill. "From" has to mean we can build it from there.
     * 2. The old ceilings OVERLAPPED. 1440p maxed at GBP 2,500 while 4K started
     *    at GBP 1,960, so a GBP 2,000 budget satisfied both bands at once and
     *    the resolution promise became ambiguous for the whole of that range.
     *
     * The table is therefore a contiguous ladder - each ceiling is the next
     * floor - so every budget maps to exactly one resolution promise.
     *
     * @see workableBands() for the live measured version, which is what the
     *      storefront, the budget slider and the clamp all read.
     *
     * @var array<string, array{min: float, max: float, label: string, floor_gpu_tier: int}>
     */
    public const RESOLUTION_BANDS = [
        '1080p' => ['min' => 1430.0, 'max' => 1640.0, 'label' => '1080p', 'floor_gpu_tier' => 2],
        '1440p' => ['min' => 1640.0, 'max' => 2290.0, 'label' => '1440p', 'floor_gpu_tier' => 3],
        '4k' => ['min' => 2290.0, 'max' => 3500.0, 'label' => '4K', 'floor_gpu_tier' => 5],
    ];


    /**
     * Resolve a requested resolution to its published band.
     *
     * Accepts the loose forms the storefront and the AI both produce
     * ("1080P", "1440p", "4K", "2160p", "QHD", "FHD", "uhd", null) and
     * returns null for anything we do not publish a band for, which the
     * callers treat as "no band constraint" rather than guessing.
     *
     * @return array{min: float, max: float, label: string, floor_gpu_tier: int}|null
     */
    public function bandFor(?string $resolution): ?array
    {
        $res = strtoupper(trim((string) $resolution));

        $key = match (true) {
            $res === '' => null,
            str_contains($res, '4K'), str_contains($res, '2160'), str_contains($res, 'UHD') => '4k',
            str_contains($res, '1440'), str_contains($res, 'QHD'), str_contains($res, '2K') => '1440p',
            str_contains($res, '1080'), str_contains($res, 'FHD') => '1080p',
            default => null,
        };

        return $key === null ? null : self::RESOLUTION_BANDS[$key];
    }

    /**
     * The entry price we advertise for a resolution, or null when we do not
     * publish a band for it. Used by the storefront, the marketing tier
     * builder and the over-budget explanation so all three quote the same
     * anchor number.
     */
    public function bandEntryPrice(?string $resolution): ?float
    {
        return $this->bandFor($resolution)['min'] ?? null;
    }


    /**
     * Swap the cpu + motherboard + cooler (+ RAM of the matching generation)
     * as one atomic platform change to bring an over-cap build back inside the
     * hard cap.
     *
     * This is deliberately a PLATFORM operation and not a per-part one. A
     * cheap Ryzen 5 5600 is useless on an LGA1851 board, and a cheap AM4 board
     * is useless with LGA1851 memory - so the only way to reach the sub-GBP
     * 1,900 1440p budgets is to move the whole platform together, which is
     * exactly what a real builder does when a customer cannot quite stretch
     * to the newer platform.
     *
     * Safety: a candidate platform is only accepted when the CPU satisfies the
     * dGPU core floor, the board genuinely fits that socket, the cooler fits
     * that socket, and RAM of the correct generation exists. Anything less
     * would produce a build that cannot be assembled.
     */
    protected function trimPlatform(array $pools, float $hardCap, ?string $resolution, bool $forceGpu, bool $debug = false): float
    {
        $selection = &$this->trimmedSelection;
        $spent = (float) collect($selection)->sum('price');

        foreach (['cpu', 'motherboard', 'cooler', 'ram'] as $required) {
            if (! isset($selection[$required])) {
                return $spent;
            }
        }

        $cpuPool = $pools['cpu'] ?? collect();
        $boardPool = $pools['motherboard'] ?? collect();
        $coolerPool = $pools['cooler'] ?? collect();
        $ramPool = $pools['ram'] ?? collect();

        if ($cpuPool->isEmpty() || $boardPool->isEmpty()) {
            return $spent;
        }

        $currentCpu = Component::find($selection['cpu']['id']);
        $currentBoard = Component::find($selection['motherboard']['id']);

        if ($currentCpu === null || $currentBoard === null) {
            return $spent;
        }

        $currentPlatformCost = (float) $selection['cpu']['price']
            + (float) $selection['motherboard']['price']
            + (float) $selection['cooler']['price']
            + (float) $selection['ram']['price'];

        $best = null;

        // Never downgrade a MODERN platform to an EOL one. The trim used to
        // swap a Ryzen 7 7800X3D + B650 + AM5 build down to a Ryzen 5 4500 +
        // A520M + AM4 whenever the cap demanded it, which is how a GBP 1,700
        // customer ask kept coming back with a 2020 platform under it
        // (boss complaint 2026-10-06). If the current build is on a modern
        // platform, entry CPUs are simply not a legal swap target. If the
        // current build is already entry-platform, entry swaps are still
        // allowed (that is the sub-GBP 1,300 value lane).
        $keepModern = ! $this->isEntryPlatformCpu($currentCpu);

        foreach ($cpuPool as $cpu) {
            if ($forceGpu && ! $this->meetsDgpuCpuStandard($cpu)) {
                continue;
            }

            if ($keepModern && $this->isEntryPlatformCpu($cpu)) {
                continue;
            }

            // Coherence (2026-09-28). A platform swap changes the processor
            // under an ALREADY-CHOSEN graphics card, so it has to respect the
            // same CPU/GPU floor the single-part trim does. Without this the
            // platform trim swapped a Ryzen 7 7800X3D + B650 + AM5 build down
            // to a Ryzen 5 4500 + A520M + AM4 on every 4K ask from GBP 2,400
            // up, leaving a GeForce RTX 5080 or RTX 5070 Ti sitting behind a
            // six-core 2020 chip that cannot feed it. Cheaper on paper,
            // incoherent in practice.
            if (isset($selection['gpu'])) {
                $chosenGpu = Component::find($selection['gpu']['id']);

                if ($chosenGpu !== null && ! $this->cpuCanFeedGpu($cpu, $chosenGpu)) {
                    continue;
                }
            }

            $socket = $this->canonicalSocket($cpu);
            if ($socket === null || $socket === $this->canonicalSocket($currentCpu)) {
                continue; // same-socket moves are handled by the single-part trim
            }

            // Cheapest legal board for this socket.
            $board = $boardPool
                ->filter(fn (Component $b) => $this->canonicalSocket($b) === $socket)
                ->sortBy('price')
                ->first();

            if ($board === null) {
                continue;
            }

            $cooler = $coolerPool
                ->filter(fn (Component $c) => $this->fitsSocket('cooler', $c, $socket))
                ->sortBy('price')
                ->first();

            if ($cooler === null) {
                continue;
            }

            $ramGen = $this->ramGenerationForSocket($socket);
            $ram = $ramGen === null
                ? $ramPool->sortBy('price')->first()
                : $ramPool
                    ->filter(fn (Component $r) => $this->ramGenerationMatches($r, $ramGen))
                    ->sortBy('price')
                    ->first();

            if ($ram === null) {
                continue;
            }

            $candidateCost = (float) $cpu->price + (float) $board->price
                + (float) $cooler->price + (float) $ram->price;

            if ($candidateCost >= $currentPlatformCost) {
                continue;
            }

            $total = $spent - $currentPlatformCost + $candidateCost;

            if ($total > $hardCap) {
                continue;
            }

            // Prefer the LARGEST total that still fits the cap, not the
            // smallest. The customer handed us a budget; leaving it unspent
            // is its own kind of failure, and minimising the total is what
            // produced the 2026-09-28 regression where a GBP 3,300 4K ask
            // came back as a Ryzen 5 4500 behind an RTX 5080 - legal on the
            // 6-core floor, but a six-year-old ten-thread chip starving a
            // flagship card, and GBP 600 of the customer's budget simply
            // discarded. Among equally good totals, prefer the faster CPU.
            $score = -($total * 1000) + $cpu->score;

            if ($best === null || $score > $best['score']) {
                $best = [
                    'score' => $score,
                    'total' => $total,
                    'cpu' => $cpu,
                    'board' => $board,
                    'cooler' => $cooler,
                    'ram' => $ram,
                ];
            }
        }

        if ($best === null) {
            return $spent;
        }

        if ($debug) {
            fwrite(STDERR, sprintf(
                "    [platform] %s + %s  ->  %s + %s (%s, %s)  parts %.2f -> %.2f  cap %.2f\n",
                mb_substr((string) $currentCpu->name, 0, 22), mb_substr((string) $currentBoard->name, 0, 18),
                mb_substr((string) $best['cpu']->name, 0, 22), mb_substr((string) $best['board']->name, 0, 18),
                $this->canonicalSocket($best['cpu']) ?? '?', $this->canonicalSocket($best['board']) ?? '?',
                $spent, $best['total'], $hardCap
            ));
        }

        foreach ([
            'cpu' => $best['cpu'],
            'motherboard' => $best['board'],
            'cooler' => $best['cooler'],
            'ram' => $best['ram'],
        ] as $slug => $part) {
            $selection[$slug]['id'] = $part->id;
            $selection[$slug]['name'] = $part->name;
            $selection[$slug]['price'] = (float) $part->price;
            $selection[$slug]['score'] = $part->score;
            $selection[$slug]['chipset'] = $part->chipset ?? null;
            $selection[$slug]['wattage'] = in_array($slug, ['cpu', 'gpu', 'psu'], true)
                ? $this->componentWattage($slug, $part)
                : 0;
        }

        return $best['total'];
    }

    /**
     * Reconcile the processor and graphics card once both are chosen.
     *
     * A build is incoherent when the card is several tiers above what the
     * processor can drive - a GeForce RTX 5080 behind a 6-core Ryzen 5 4500
     * cannot convert the card's frames, so we would be quoting a machine that
     * wastes the customer's money on hardware the rest of the build cannot
     * use. Every individual part passed its own floor, which is exactly why
     * this needed its own pass.
     *
     * Order of preference, because the two repairs are not equal:
     *   1. Upgrade the processor to the best-scoring coherent one that still
     *      fits the hard cap. This spends the customer's budget on the part
     *      that was starved and is almost always what we want.
     *   2. Only if no coherent processor is affordable, step the card down to
     *      the best tier the existing processor can drive. We would rather
     *      quote a 4K card that is properly fed than a flagship that is not.
     *
     * Socket is respected on the CPU upgrade: the board and cooler are already
     * committed, so the replacement processor has to share their socket.
     */
    protected function enforceCpuGpuCoherence(array $selection, array $pools, float $hardCap, ?string $resolution, bool $debug = false): array
    {
        if (! isset($selection['cpu'], $selection['gpu'])) {
            return $selection;
        }

        $cpu = Component::find($selection['cpu']['id']);
        $gpu = Component::find($selection['gpu']['id']);

        if ($cpu === null || $gpu === null) {
            return $selection;
        }

        if ($this->cpuCanFeedGpu($cpu, $gpu)) {
            return $selection;
        }

        $cpuPrice = (float) $selection['cpu']['price'];
        $gpuPrice = (float) $selection['gpu']['price'];
        $spent = (float) collect($selection)->sum('price');

        // --- Repair 1: can a coherent processor be afforded? ---------------
        $socket = $this->canonicalSocket($cpu);

        $upgrade = ($pools['cpu'] ?? collect())
            ->filter(fn (Component $c) => $c->id !== $cpu->id)
            ->filter(fn (Component $c) => $this->cpuCanFeedGpu($c, $gpu))
            ->filter(fn (Component $c) => ! $this->hasIntegratedGraphics($c))
            ->filter(fn (Component $c) => $socket === null || $this->canonicalSocket($c) === $socket)
            ->sortByDesc('score')
            ->first();

        if ($upgrade !== null && $spent - $cpuPrice + (float) $upgrade->price <= $hardCap) {
            if ($debug) {
                fwrite(STDERR, sprintf(
                    "    [coherence] cpu %s -> %s (%.2f -> %.2f) to feed %s, parts %.2f cap %.2f\n",
                    mb_substr((string) $cpu->name, 0, 24), mb_substr((string) $upgrade->name, 0, 24),
                    $cpuPrice, (float) $upgrade->price, mb_substr((string) $gpu->name, 0, 22),
                    $spent, $hardCap
                ));
            }

            return $this->withPart($selection, 'cpu', $upgrade);
        }

        // --- Repair 2: otherwise step the card down to what the CPU drives --
        $gpuTier = $this->gpuPerformanceTier($gpu);

        $downgrade = ($pools['gpu'] ?? collect())
            ->filter(fn (Component $g) => $g->id !== $gpu->id)
            ->filter(fn (Component $g) => $this->gpuMeetsResolution($g, $resolution))
            ->filter(fn (Component $g) => $this->gpuPerformanceTier($g) < $gpuTier)
            ->filter(fn (Component $g) => $this->cpuCanFeedGpu($cpu, $g))
            ->sortByDesc('score')
            ->first();

        if ($downgrade !== null) {
            if ($debug) {
                fwrite(STDERR, sprintf(
                    "    [coherence] gpu %s (tier %d) -> %s (tier %d); %s cannot drive tier %d\n",
                    mb_substr((string) $gpu->name, 0, 22), $gpuTier,
                    mb_substr((string) $downgrade->name, 0, 22),
                    $this->gpuPerformanceTier($downgrade), mb_substr((string) $cpu->name, 0, 22), $gpuTier
                ));
            }

            return $this->withPart($selection, 'gpu', $downgrade);
        }

        return $selection;
    }

    /**
     * Replace one category in a selection with a different component,
     * keeping the internal wattage field in step for the power pass.
     */
    protected function withPart(array $selection, string $slug, Component $part): array
    {
        $selection[$slug]['id'] = $part->id;
        $selection[$slug]['name'] = $part->name;
        $selection[$slug]['price'] = (float) $part->price;
        $selection[$slug]['score'] = $part->score;
        $selection[$slug]['chipset'] = $part->chipset ?? null;
        $selection[$slug]['wattage'] = in_array($slug, ['cpu', 'gpu', 'psu'], true)
            ? $this->componentWattage($slug, $part)
            : 0;

        return $selection;
    }

    /**
     * The build produced by the last trimToCap() call.
     *
     * PHP has no out-parameters, and returning both the selection and the
     * new parts total from every call site would be noisier than one
     * documented instance property. It is written and read only inside
     * pickBalancedBuild(), so there is no cross-request state.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $trimmedSelection = [];

    /**
     * The mirror image of trimToCap(): step parts UP while the money allows.
     *
     * trimToCap() exists because the per-category relax-to-cheapest fallbacks
     * ignore the cap, so an over-budget build has to be walked back down.
     * This exists for the opposite failure: a build that finished UNDER the cap
     * because every stage maximises a score inside a per-category share and then
     * stops, with nothing anywhere owning the leftover money.
     *
     * Measured 2026-10-07 on the published bands: GBP 2,500/1440p returned a
     * GeForce RTX 5060 Ti - the same card as the GBP 1,700 build, for GBP 800
     * more - and GBP 3,500/4K left GBP 147 unspent.
     *
     * SAFETY CONTRACT (this is a customer-promise path, so it fails closed):
     *
     *  - Only a STRICTLY better part is ever accepted. There is no branch that
     *    can step a tier down, and "better" is capability, not price: a cheaper
     *    card with the best pounds-per-frame loses to the current one. An
     *    earlier attempt ranked candidates by scoreComponent(), which rewards
     *    value, and it installed a GBP 253 Arc A580 into a GBP 1,763 1080p
     *    build - raising the price while lowering the capability.
     *  - Every candidate must fit under $hardCap, so the budget cannot break.
     *  - The GPU tier may only INCREASE, so the resolution floor buildPools()
     *    already enforced can never be broken here.
     *  - RAM is re-filtered to the selected CPU's memory generation, because
     *    the pools are socket-filtered only inside the selection loop and this
     *    pass runs after it.
     *  - A slot that is absent is never filled and a slot we cannot rank is
     *    never touched, so an unknown part is left exactly as it was rather
     *    than being "improved" on a guess.
     *
     * Steps run in capability-per-pound order - the graphics card first,
     * because it is what the customer notices, then the processor, then
     * memory and storage. It stops as soon as a step finds nothing, so a build
     * is never reshuffled just to move money around.
     */
    protected function spendSlack(array $selection, array $pools, float $hardCap, bool $forceGpu, bool $debug = false): array
    {
        if ($selection === []) {
            return $selection;
        }

        // The CPU drives two of the compatibility gates below, so resolve it
        // once from the finished machine rather than trusting the pools.
        $cpu = null;

        if (isset($selection['cpu']['id'])) {
            $cpu = Component::find((int) $selection['cpu']['id']);
        }

        $cpuBrand = $cpu === null ? null : $this->cpuBrandOf(['name' => $cpu->name]);
        $cpuSocket = $cpu === null ? null : $this->canonicalSocket($cpu);

        foreach (['gpu', 'cpu', 'ram', 'storage'] as $slug) {
            if (! $forceGpu && $slug === 'gpu') {
                continue;
            }

            if (! isset($selection[$slug]['id'])) {
                continue;
            }

            $pool = $pools[$slug] ?? collect();

            if ($pool->isEmpty()) {
                continue;
            }

            $current = Component::find((int) $selection[$slug]['id']);

            if ($current === null) {
                continue;
            }

            $budget = $hardCap - (float) collect($selection)->sum('price');

            if ($budget <= 1.0) {
                return $selection;
            }

            $better = match ($slug) {
                'gpu' => $this->betterGpu($pool, $current, $budget, $cpuBrand),
                'cpu' => $this->betterCpu($pool, $current, $budget),
                'ram' => $this->betterMemory($pool, $current, $budget, $cpuSocket),
                'storage' => $this->betterStorage($pool, $current, $budget),
                default => null,
            };

            if ($better === null) {
                continue;
            }

            $delta = (float) $better->price - (float) $current->price;

            if ($debug) {
                fwrite(STDERR, sprintf(
                    "    slack %-9s %-40s %8.2f -> %-40s %8.2f (+%.2f)\n",
                    $slug,
                    mb_substr((string) $current->name, 0, 40), (float) $current->price,
                    mb_substr((string) $better->name, 0, 40), (float) $better->price, $delta
                ));
            }

            $selection[$slug] = $this->selectionRow($slug, $better);
        }

        return $selection;
    }

    /**
     * The best graphics card that is genuinely a step UP from the current one
     * and still fits $budget.
     *
     * Ranked by tier first and price second, so the step lands on the cheapest
     * card in the next tier rather than on the most expensive card that happens
     * to fit. viablePool() is re-applied because the CPU brand pairing gate
     * lives in the selection loop, not in the pool.
     */
    protected function betterGpu($pool, Component $current, float $budget, ?string $cpuBrand): ?Component
    {
        $tier = $this->gpuPerformanceTier($current);

        // gpuPerformanceTier() returns 0 for a card it cannot classify. Rule 6:
        // an unknown card must never be treated as permitted, and it must
        // never be used to justify a spend either, so we refuse to step from it.
        if ($tier <= 0) {
            return null;
        }

        return $this->viablePool('gpu', $pool, $cpuBrand)
            ->filter(fn (Component $c) => $this->gpuPerformanceTier($c) > $tier)
            ->filter(fn (Component $c) => (float) $c->price > (float) $current->price)
            ->filter(fn (Component $c) => (float) $c->price - (float) $current->price <= $budget + 0.001)
            ->sortBy(fn (Component $c) => [$this->gpuPerformanceTier($c), (float) $c->price])
            ->first();
    }

    /**
     * A processor strictly above the current one on generation, still fitting.
     */
    protected function betterCpu($pool, Component $current, float $budget): ?Component
    {
        // generationScore() requires the Category. If it cannot be resolved we
        // refuse the step rather than passing null into a typed parameter - an
        // unrankable slot must be left alone, never guessed at.
        $category = $this->categoryFor('cpu');

        if ($category === null) {
            return null;
        }

        $tier = $this->generationScore($current, $category);

        if ($tier <= 0) {
            return null;
        }

        return $pool
            ->filter(fn (Component $c) => $this->generationScore($c, $category) > $tier)
            ->filter(fn (Component $c) => (float) $c->price > (float) $current->price)
            ->filter(fn (Component $c) => (float) $c->price - (float) $current->price <= $budget + 0.001)
            ->sortBy(fn (Component $c) => [$this->generationScore($c, $category), (float) $c->price])
            ->first();
    }

    /**
     * More memory, or the same memory faster, still fitting and still legal for
     * the CPU's memory generation.
     */
    protected function betterMemory($pool, Component $current, float $budget, ?string $cpuSocket): ?Component
    {
        $generation = $this->ramGenerationForSocket($cpuSocket);

        if ($generation !== null) {
            $pool = $pool->filter(fn (Component $c) => $this->ramGenerationMatches($c, $generation));

            if ($pool->isEmpty()) {
                return null;
            }
        }

        $rank = fn (Component $c) => $this->ramCapacityGb($c) * 1000 + (int) preg_replace('/\D/', '', (string) $this->memorySpeedToken($c));
        $currentRank = $rank($current);

        return $pool
            ->filter(fn (Component $c) => $rank($c) > $currentRank)
            ->filter(fn (Component $c) => (float) $c->price > (float) $current->price)
            ->filter(fn (Component $c) => (float) $c->price - (float) $current->price <= $budget + 0.001)
            ->sortBy(fn (Component $c) => [$rank($c), (float) $c->price])
            ->values()
            ->first();
    }

    /**
     * A larger or faster drive, still fitting.
     */
    protected function betterStorage($pool, Component $current, float $budget): ?Component
    {
        $rank = fn (Component $c) => $this->specCapacityGb($c);
        $currentRank = $rank($current);

        return $pool
            ->filter(fn (Component $c) => $rank($c) > $currentRank)
            ->filter(fn (Component $c) => (float) $c->price > (float) $current->price)
            ->filter(fn (Component $c) => (float) $c->price - (float) $current->price <= $budget + 0.001)
            ->sortBy(fn (Component $c) => [$rank($c), (float) $c->price])
            ->first();
    }

    /** @var array<string, Category|null> */
    protected array $categoryCache = [];

    /**
     * The Category row for a slug, cached for the request.
     *
     * spendSlack() is handed pools keyed by slug rather than the models, but
     * ranking by generation needs the Category. Without a cache this is one
     * query per step, and fitToCeiling() re-runs the picker up to seven times
     * per ask.
     */
    protected function categoryFor(string $slug): ?Category
    {
        if (! array_key_exists($slug, $this->categoryCache)) {
            $this->categoryCache[$slug] = Category::query()->where('slug', $slug)->first();
        }

        return $this->categoryCache[$slug];
    }

    /**
     * Build a selection row in the shape pickBalancedBuild() writes, so a
     * slack step produces a part indistinguishable from a picked one.
     */
    protected function selectionRow(string $slug, Component $component): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'price' => (float) $component->price,
            'score' => 0,
            'chipset' => $component->chipset ?? null,
            'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true)
                ? $this->componentWattage($slug, $component)
                : 0,
        ];
    }

    /**
     * Step discretionary parts down until the parts total fits $hardCap.
     *
     * Order of sacrifice is deliberate: graphics card first (largest single
     * discretionary line, and the resolution floor still protects the
     * customer's honest resolution promise), then the processor, then the
     * case. RAM, storage, board, PSU and cooler are never touched - they
     * are the spec floors and warranty items we refuse to cheapen.
     *
     * Returns the new parts total. The trimmed build is available on
     * $this->trimmedSelection immediately afterwards.
     */
    protected function trimToCap(array $selection, array $pools, float $hardCap, ?string $resolution, bool $forceGpu, bool $debug = false): float
    {
        $this->trimmedSelection = $selection;

        $spent = (float) collect($selection)->sum('price');

        if ($spent <= $hardCap) {
            return $spent;
        }

        // Platform-level trim FIRST. The single-part trim below is socket-locked
        // because the board and cooler are already committed, which means it
        // cannot help the case that actually needs help: a GBP 1,900 1440p ask
        // that landed on an LGA1851 Core Ultra 7, where every cheap six-core
        // alternative is AM4 and so unreachable without also changing the
        // board. A platform swap moves cpu + board + cooler + RAM generation
        // together, which is the only way those budgets are reachable.
        $spent = $this->trimPlatform($pools, $hardCap, $resolution, $forceGpu, $debug);

        if ($spent <= $hardCap) {
            return $spent;
        }

        $trimOrder = $forceGpu
            ? ['gpu', 'cpu', 'case']
            : ['cpu', 'case'];

        foreach ($trimOrder as $slug) {
            if (! isset($this->trimmedSelection[$slug])) {
                continue;
            }

            $pool = $pools[$slug] ?? collect();
            if ($pool->isEmpty()) {
                continue;
            }

            // Re-apply the same legality filters the picker used, so the trim
            // can never substitute a part the build is not allowed to take.
            if ($slug === 'gpu' && $forceGpu) {
                $pool = $pool->filter(fn (Component $c) => $this->gpuMeetsResolution($c, $resolution));
            }

            if ($slug === 'cpu' && $forceGpu) {
                // CPU/GPU coherence. When the card is already chosen (the
                // ordered list picks cpu first, so this applies on the dream
                // build and the re-pick paths), the processor must be able to
                // feed it. Failure here is silent and expensive - a GBP 3,300
                // 4K machine came back as a 6-core 4500 behind an RTX 5080.
                if (isset($selection['gpu'])) {
                    $chosenGpu = Component::find($selection['gpu']['id']);
                    $coherent = $pool->filter(
                        fn (Component $c) => $this->cpuCanFeedGpu($c, $chosenGpu)
                    );

                    if ($coherent->isNotEmpty()) {
                        $pool = $coherent;
                    }
                }

                $pool = $pool->filter(fn (Component $c) => $this->meetsDgpuCpuStandard($c));
            }

            // Coherence lock (2026-09-28). The trim must never trade a part
            // into a pair the other half cannot drive. Without this the budget
            // trim quietly undid the coherence pass and put a Ryzen 5 4500
            // back behind a GeForce RTX 5080 on every 4K build between GBP
            // 2,400 and 3,300 - legal on the 6-core floor, incoherent in
            // practice, and exactly the quote that costs a 5-star review.
            if ($slug === 'cpu' && isset($this->trimmedSelection['gpu'])) {
                $chosenGpu = Component::find($this->trimmedSelection['gpu']['id']);

                if ($chosenGpu !== null) {
                    $pool = $pool->filter(fn (Component $c) => $this->cpuCanFeedGpu($c, $chosenGpu));
                }
            }

            if ($slug === 'gpu' && isset($this->trimmedSelection['cpu'])) {
                $chosenCpu = Component::find($this->trimmedSelection['cpu']['id']);

                if ($chosenCpu !== null) {
                    $pool = $pool->filter(fn (Component $g) => $this->cpuCanFeedGpu($chosenCpu, $g));
                }
            }

            $current = $this->trimmedSelection[$slug];

            // Socket lock (correctness, added 2026-09-28). The motherboard and
            // cooler were already chosen for the CURRENT cpu's socket, so a
            // trim swap to a different socket would produce a machine that
            // physically cannot be built - a far worse failure than an honest
            // over-budget quote. The processor may therefore only be traded
            // for another chip on the same socket.
            if ($slug === 'cpu') {
                $currentCpu = Component::find($current['id']);
                $socket = $currentCpu === null ? null : $this->canonicalSocket($currentCpu);

                if ($socket !== null) {
                    $pool = $pool->filter(
                        fn (Component $c) => $this->canonicalSocket($c) === $socket
                    );
                }
            }

            $alternatives = $pool
                ->filter(fn (Component $c) => $c->id !== $current['id'])
                ->filter(fn (Component $c) => (float) $c->price < (float) $current['price'])
                ->sortByDesc('score');

            foreach ($alternatives as $alt) {
                if ($spent <= $hardCap) {
                    break 2;
                }

                $next = $spent - (float) $current['price'] + (float) $alt->price;

                if ($next > $hardCap) {
                    // This swap alone does not fix the overrun; keep looking
                    // for something cheaper rather than settling.
                    continue;
                }

                if ($debug) {
                    fwrite(STDERR, sprintf(
                        "    [trim] %-6s %s (%.2f) -> %s (%.2f)  parts %.2f -> %.2f  cap %.2f\n",
                        $slug, mb_substr((string) $current['name'], 0, 26), (float) $current['price'],
                        mb_substr((string) $alt->name, 0, 26), (float) $alt->price,
                        $spent, $next, $hardCap
                    ));
                }

                $this->trimmedSelection[$slug]['id'] = $alt->id;
                $this->trimmedSelection[$slug]['name'] = $alt->name;
                $this->trimmedSelection[$slug]['price'] = (float) $alt->price;
                $this->trimmedSelection[$slug]['score'] = $alt->score;
                $this->trimmedSelection[$slug]['chipset'] = $alt->chipset ?? null;
                $this->trimmedSelection[$slug]['wattage'] = in_array($slug, ['cpu', 'gpu', 'psu'], true)
                    ? $this->componentWattage($slug, $alt)
                    : 0;

                $current = $this->trimmedSelection[$slug];
                $spent = $next;
            }
        }

        return $spent;
    }

    /**
     * Categories whose PCTG spec floor had to be relaxed because nothing in
     * stock met it. Non-empty means a build from this run is sub-standard and
     * must not be quoted or published.
     *
     * @var array<string, true>
     */
    protected array $floorDegradations = [];

    /**
     * Was a spec floor relaxed during the last pool build? Empty array = every
     * build from this run met the PCTG minimums.
     *
     * @return array<string, true>
     */
    public function floorDegradations(): array
    {
        return $this->floorDegradations;
    }


    /**
     * Return both the best-value build for the customer's budget AND the
     * ideal (newest/best) build regardless of price.
     *
     * @return array{budget: array, ideal: array}
     */
    public function recommendBoth(float $budget, ?string $purpose = null, ?string $resolution = null, ?int $userId = null): array
    {
        $categories = Category::active()->ordered()->get();
        $pools = $this->buildPools($categories, $purpose, $resolution);

        $ai = $this->gemini->scoreWeights($pools, $budget, $purpose, $resolution);

        if ($ai !== null) {
            $this->applyWeights($pools, $ai);
        }

        $budgetBuild = $this->pickBudgetBuild($pools, $budget, $resolution);
        $idealBuild = $this->pickIdealBuild($categories, $purpose, $resolution);

        // Attach the AI metadata AFTER per-build rationale is resolved so the
        // weights strategy line and the build-specific line can coexist.
        $budgetBuild['ai'] = $ai !== null ? [
            'provider' => 'gemini',
            'model' => config('gemini.model'),
            'rationale' => $budgetBuild['ai']['rationale'] ?? ($ai['rationale'] ?? null),
        ] : null;
        $idealBuild['ai'] = $ai !== null ? [
            'provider' => 'gemini',
            'model' => config('gemini.model'),
            'rationale' => $idealBuild['ai']['rationale'] ?? ($ai['rationale'] ?? null),
        ] : null;

        // Per-build rationale (why THESE parts) â€” cached and optional; falls
        // back to weights-strategy rationale or nothing when Gemini is off.
        $this->attachBuildRationale($budgetBuild, $budget, $purpose, $resolution);
        $this->attachBuildRationale($idealBuild, 0, $purpose, $resolution);

        // Complete flag: prefers the decorated value (pickers already guarantee
        // completeness, including APU builds that legitimately skip the GPU).
        $budgetBuild['complete'] = $budgetBuild['complete'] ?? $this->isComplete($budgetBuild['components'], $this->hasApuComponents($budgetBuild['components']));
        $idealBuild['complete'] = $idealBuild['complete'] ?? $this->isComplete($idealBuild['components'], $this->hasApuComponents($idealBuild['components']));

        // The QC gate travels with each build: price freshness, relaxed spec
        // floors and completeness. Quoting/marketing surfaces must respect
        // $build['qualityGate']['publishable'] before showing a price.
        $budgetBuild = $this->attachPublishGate($budgetBuild);
        $idealBuild = $this->attachPublishGate($idealBuild);

        if ($userId !== null) {
            AiRecommendation::create([
                'user_id' => $userId,
                'budget' => $budget,
                'purpose' => $purpose,
                'resolution' => $resolution,
                'recommendation' => $budgetBuild['components'],
            ]);
        }

        return [
            'budget' => $budgetBuild,
            'ideal' => $idealBuild,
        ];
    }

    /**
     * Pick the best value component per category within a budget.
     *
     * @return array{components: array<string, array{id: int, name: string, price: float, score: int}>, total: float, remaining: float, ai?: array<string, mixed>}
     */
    public function recommend(float $budget, ?string $purpose = null, ?string $resolution = null, ?int $userId = null): array
    {
        $categories = Category::active()->ordered()->get();
        $pools = $this->buildPools($categories, $purpose, $resolution);

        $ai = $this->gemini->scoreWeights($pools, $budget, $purpose, $resolution);

        if ($ai !== null) {
            $this->applyWeights($pools, $ai);
        }

        $result = $this->pickBudgetBuild($pools, $budget, $resolution);
        // APU builds legitimately ship without a discrete GPU, so completeness
        // has to be judged with the same exemption recommendBoth() and the
        // floor/relax helpers already use. This call was the one place that
        // did not, which marked every APU build incomplete and therefore
        // unpublishable - i.e. it silently locked out the whole entry lane
        // (the GBP 785.85 Ryzen 3 3200G box) behind the "missing a required
        // part" blocker while every dGPU build sailed through.
        $result['complete'] = $this->isComplete($result['components'], $this->hasApuComponents($result['components']));

        if ($ai !== null) {
            $result['ai'] = [
                'provider' => 'gemini',
                'model' => config('gemini.model'),
                'rationale' => $ai['rationale'] ?? null,
            ];
        }

        // Per-build rationale (why THESE parts) â€” cached and optional; must run
        // after the ai block or it would be clobbered.
        $this->attachBuildRationale($result, $budget, $purpose, $resolution);

        // The QC gate travels with the result: freshness, relaxed floors and
        // completeness. Quoting/marketing surfaces must respect
        // $result['qualityGate']['publishable'] before showing a price.
        $result = $this->attachPublishGate($result);

        if ($userId !== null) {
            AiRecommendation::create([
                'user_id' => $userId,
                'budget' => $budget,
                'purpose' => $purpose,
                'resolution' => $resolution,
                'recommendation' => $result['components'],
            ]);
        }

        return $result;
    }

    /**
     * Score every active component in every category and return pools keyed
     * by category slug, ready for the greedy pick loop.
     *
     * Corrupt-price rows (bundle/pack-of-N prices captured by the scraper) are
     * quarantined out of every pool first â€” quoting one to a customer would be
     * an instant 5-star reputation failure â€” and so are rows that fail the PCTG
     * spec floor (sub-32GB memory, spinning drives).
     */
    protected function buildPools($categories, ?string $purpose, ?string $resolution): array
    {
        // Per-run state: a relaxed floor must only ever be reported for the
        // build it happened in, never carried into a later one on the same
        // service instance.
        $this->floorDegradations = [];

        $pools = [];

        foreach ($categories as $category) {
            $pool = Component::active()
                ->where('category_id', $category->id)
                ->where('stock', '>', 0)
                ->get();

            $pool = $this->priceIntegrity->clean($pool);

            // Only report a floor failure when the pool would otherwise be
            // empty, so an unreadable-catalogue category degrades to the
            // relax-to-cheapest fallback rather than breaking build generation.
            $pool = $pool->reject(
                fn (Component $component) => ! $this->passesSpecFloor($component, $category)
            );
            if ($pool->isEmpty()) {
                // Record the relaxation. An *unrecorded* fallback previously
                // made the gate a silent no-op on exactly the categories it was
                // meant to guard: the floor emptied the pool, the refill put
                // every unfiltered row back, and the 4GB kits sailed through.
                // Now the quoting surface can refuse to publish this build.
                $this->floorDegradations[$category->slug] = true;

                $pool = Component::active()
                    ->where('category_id', $category->id)
                    ->where('stock', '>', 0)
                    ->get();
                $pool = $this->priceIntegrity->clean($pool);
            }

            // The quoting gate. A part we cannot positively identify must never
            // reach a customer, whatever its score.
            //
            // Measured 2026-10-07: 115 of 306 graphics cards were quotable as
            // "Asus PRIME OC" or "Gigabyte GAMING OC" - a board partner's cooler
            // with no GPU model at all, priced at GBP 555. Nothing in the
            // scoring or resolution logic objected, because the tier was
            // derived from the same uninformative name. That is a
            // hallucinated spec in a live quote, and it is the kind of defect
            // no amount of budget tuning will catch, so it is gated here.
            //
            // The data is fixed (scripts/fix-gpu-identity.php rebuilt all 116
            // from specs.chipset and the vendor SKU), so this currently rejects
            // nothing in the GPU category. It stays because the gate must not
            // depend on the data being clean today.
            //
            // IT CAN NEVER EMPTY A CATEGORY. If the gate would remove every
            // row, the pool is left untouched and the reason is logged, because
            // a silently empty category produces no builds at all, which is a
            // far worse and far less visible failure than the one being
            // prevented.
            $quotable = $pool->filter(
                fn (Component $component) => $this->isQuotable($component, $category)
            );

            if ($quotable->isEmpty() && $pool->isNotEmpty()) {
                // Rule 5: a fallback that changes the result must announce
                // itself by name.
                \Log::warning('quotable gate refused to empty a category; the gate is not filtering', [
                    'category' => $category->slug,
                    'rows_in_pool' => $pool->count(),
                ]);
            } else {
                $pool = $quotable;
            }

            if ($pool->isNotEmpty()) {
                $maxPrice = $pool->max(fn (Component $component) => (float) $component->price);
            } else {
                $maxPrice = 1.0;
            }

            $pools[$category->slug] = $pool
                ->each(fn (Component $component) => $component->score = $this->scoreComponent($component, $category, $purpose, $resolution, $maxPrice));
        }

        return $pools;
    }

    /**
     * Can this part be quoted to a customer with a straight face?
     *
     * This is deliberately NOT "does the name look complete". Each test asks
     * whether the field a customer or a compatibility rule actually READS can
     * be read off this row. That distinction is the whole point: coolers have
     * no `cooler_type` and no `specs` at all on any of their 372 rows, yet
     * "Noctua NH-D15 chromax.black" is a perfectly quotable product, so
     * coolers are judged on the name. Gate them on the empty column instead
     * and the entire category leaves the shop overnight.
     *
     * Per category, the consumer is:
     *
     *   gpu         gpuPerformanceTier() + the resolution filter read the model
     *   cpu         fitsSocket() reads `socket`
     *   ram         ramGenerationMatches() reads the memory generation
     *   storage     the spec floor reads capacity and `interface`
     *   psu         ensurePowerAdequacy() reads `wattage`
     *   motherboard fitsSocket() reads `socket`
     *   case, cooler  the name
     *
     * Anything not listed returns true: an unrecognised category is left to
     * its own spec floor rather than being silently emptied here.
     */
    protected function isQuotable(Component $component, Category $category): bool
    {
        return match ($category->slug) {
            'gpu' => preg_match('/\b(RTX\s?\d{4}|RX\s?\d{4}|GTX\s?\d{4}|GT\s?\d{3}|Arc\s?[AB]\d{3}|Quadro\s?R?T?\d{3,4}|Radeon\s?Pro)/i', (string) $component->name) === 1
                && $this->gpuPerformanceTier($component) > 0,
            'cpu' => trim((string) $component->socket) !== ''
                || preg_match('/\b(i[3579]|Ryzen|Athlon|Core|Threadripper|Xeon|Pentium)\b/i', (string) $component->name) === 1,
            'ram' => trim((string) $component->memory_type) !== ''
                && $this->memorySpeedToken($component) !== null,
            'storage' => trim((string) $component->interface) !== ''
                && $this->specCapacityGb($component) > 0,
            'psu' => $this->psuWattage($component) > 0,
            'motherboard' => trim((string) $component->socket) !== '',
            default => true,
        };
    }

    /**
     * Attach a cached, per-build rationale (why these specific parts) to a
     * build. Pure enhancement: any failure just leaves the build without it.
     *
     * @param  array<string, mixed>  $build
     */
    protected function attachBuildRationale(array &$build, float $budget, ?string $purpose, ?string $resolution): void
    {
        $rationale = $this->gemini->describeBuild(
            $build['components'] ?? [],
            $budget,
            $purpose,
            $resolution
        );

        if (is_string($rationale) && $rationale !== '') {
            $build['ai']['rationale'] = $rationale;
        }
    }

    /**
     * Apply optional Gemini per-category weight multipliers to scored pools.
     */
    protected function applyWeights(array $pools, array $ai): void
    {
        foreach ($pools as $slug => $pool) {
            $weight = $ai['weights'][$slug] ?? 1.0;

            if (! is_numeric($weight) || (float) $weight <= 0) {
                continue;
            }

            $pool->each(
                fn (Component $component) => $component->score = (int) round($component->score * (float) $weight)
            );
        }
    }

    /**
     * Greedy pick that respects per-category budget shares and a hard cap.
     * Slack from under-spending in one category rolls forward to the next.
     *
     * The customer-facing budget is the SYSTEM budget (the one complete price
     * they see at checkout), so parts are picked against the effective parts
     * budget that leaves room for the hidden margin. The returned total is the
     * complete price â€” never a parts sum.
     */
    protected function pickBudgetBuild(array $pools, float $budget, ?string $resolution = null): array
    {
        // Cooling depends on whether we end up with a discrete GPU
        // (boss directive 2026-09-27):
        //   - dedicated GPU  -> all-in-one liquid cooler
        //   - APU/iGPU only  -> air cooler
        $poolsWithGpu = $this->dedicatedGpuPools($pools);
        $poolsApu = $this->integratedGpuPools($pools);

        // 1) Dedicated-GPU build â€” liquid cooling.
        $gpu = $this->fitToCeiling(
            fn (float $target) => $this->pickBalancedBuild($poolsWithGpu, $target, forceGpu: true, needApu: false, resolution: $resolution),
            $budget,
            allowMissingGpu: false
        );
        $gpuComplete = $this->isComplete($gpu['components']);

        // 2) APU (no dedicated GPU) build â€” air cooling, complete + warning.
        $apu = $this->fitToCeiling(
            fn (float $target) => $this->pickBalancedBuild($poolsApu, $target, forceGpu: false, needApu: true, resolution: $resolution),
            $budget,
            allowMissingGpu: true
        );
        $apuComplete = $this->isComplete($apu['components'], allowMissingGpu: true);

        // Affordability rule (boss directive 2026-09-27): never hand someone a
        // machine they cannot pay for when we can build one they can. With the
        // 32GB + liquid-cooling floor in place the cheapest dedicated-GPU
        // system is ~Â£907 all-in, so a Â£400 or Â£600 customer was being shown
        // only a Â£907 quote. Below that floor the integrated-graphics system is
        // the honest entry product, clearly labelled as having no dedicated
        // graphics card.
        //
        // THE APU LANE IS RETIRED (boss directive 2026-09-28).
        //
        // "The lowest recommendation should be a Ryzen 5 4500 or an Intel
        // Core i5-12400." Neither part has integrated graphics, so a machine
        // built around them cannot drive a display without a graphics card.
        // That retires the GPU-less lane outright: there is no longer a build
        // we are willing to recommend at all without a discrete GPU, and the
        // Ryzen 3 3200G box that was the old GBP 1,043.58 entry product is no
        // longer quotable.
        //
        // The honest consequence is that the cheapest machine we sell is now
        // a dGPU machine on a six-core processor, and the 1080p band floor
        // moves up to meet it. We would rather quote one pound more than sell
        // a four-core 2018 APU box that we then have to apologise for.
        //
        // $poolsApu is still built (it is the base pool the fallback paths
        // use) but is no longer offered as a recommendation.
        if ($gpuComplete) {
            return $this->decorateBuild($gpu, $budget, 'gpu', apuWarning: false, resolution: $resolution);
        }

        if ($apuComplete) {
            // A GPU-less machine should now be unreachable, but if the
            // catalogue cannot supply a graphics card we would rather quote
            // the complete machine than refuse outright.
            return $this->decorateBuild($apu, $budget, 'apu', apuWarning: true, resolution: $resolution);
        }

        // 3) Cheapest complete system we can physically build (nearest to
        // budget). The pool is chosen so the cooling rule still holds - the
        // base pool contains both air towers and AIO units, so picking from it
        // could put a GBP 7.50 air tower behind a dedicated GPU.
        $fallbackPools = $gpuComplete ? $poolsWithGpu : $poolsApu;
        $cheapest = $this->pickCheapestComplete($fallbackPools, $resolution);
        if ($cheapest !== null && $this->isComplete($cheapest['components'], allowMissingGpu: true)) {
            return $this->decorateBuild($cheapest, $budget, 'cheapest', apuWarning: $this->hasApuComponents($cheapest['components']), resolution: $resolution);
        }

        // 4) Absolute fallback â€” keep whatever we have (should never happen).
        $components = array_merge($gpu['components'] ?? [], $apu['components'] ?? []);
        $spent = collect($components)->sum('price');

        return [
            'components' => $components,
            'total' => $this->pricing->completePrice($spent),
            'remaining' => 0.0,
            'complete' => false,
        ];
    }

    /**
     * Re-pick against a damped budget until the build fits the publish ceiling.
     *
     * The picker maximises quality inside a parts cap rather than minimising
     * price, so a mid-band ask could land 6-20% over: measured 2026-09-28, a
     * GBP 1,200 ask produced a GBP 1,388 dGPU box (15.6% over) and the dGPU
     * lane only started honouring its own budget at GBP 1,950. The gate
     * correctly rejected those, which meant most of the price ladder could not
     * be sold at all.
     *
     * Rather than hand-rolling a downgrade pass - which would have to re-derive
     * socket locking, RAM generation matching, AIO-versus-air cooling, the PSU
     * wattage floor and the spec floors, all of which the picker already
     * enforces - this re-invokes the picker with a reduced target and lets it
     * re-solve the whole machine legally. The ceiling is
     * budget x (1 + pricing.budget_ceiling_tolerance), i.e. exactly what
     * attachPublishGate() is willing to let through.
     *
     * Every attempt that is complete is remembered and the cheapest one is
     * returned, so this can never return a worse build than the single
     * unadjusted pick it replaces. The step is damped because the picker is not
     * strictly monotonic in budget: measured overshoot on some asks rises and
     * falls as the target moves, so a naive ratio step can oscillate.
     */
    protected function fitToCeiling(callable $picker, float $budget, bool $allowMissingGpu = false): array
    {
        $tolerance = (float) config('pricing.budget_ceiling_tolerance', 0.05);
        $ceiling = $budget * (1 + $tolerance);

        // Quoted-budget platform floor (boss complaint 2026-10-06): a build
        // sold at GBP 1,700 must not be built on a 2020 AM4 platform. The
        // pick lanes re-admit entry-platform CPUs whenever the damped retry
        // budget dips below GBP 1,300, and the old "cheapest complete wins"
        // rule always preferred that attempt - which is why the 4500 came
        // back no matter what the pool gate said. So attempts at/above the
        // entry ceiling are only accepted if their CPU is a modern-platform
        // part. If no modern attempt completes, we still return the cheapest
        // (better to quote something than vanish), but never before trying.
        $wantModernPlatform = ! $allowMissingGpu && $budget >= self::ENTRY_PLATFORM_CEILING;
        $modernBest = null;
        $modernBestTotal = null;

        $best = $picker($budget);
        $bestTotal = $this->allInFor($best['components'] ?? []);

        if ($wantModernPlatform && $this->isComplete($best['components'] ?? [], $allowMissingGpu) && $this->attemptHasModernCpu($best)) {
            $modernBest = $best;
            $modernBestTotal = $bestTotal;
        }

        if (! $this->isComplete($best['components'] ?? [], $allowMissingGpu)) {
            // Nothing complete yet; a lower target will not rescue an
            // incomplete pick, so hand it straight back for the existing
            // cheapest-complete fallback to deal with.
            return $best;
        }

        if ($bestTotal <= $ceiling) {
            // Fits on the first try - and modern if required (already
            // captured above), otherwise the attempt is the same one.
            if ($wantModernPlatform) {
                return $modernBest ?? $best;
            }

            return $best;
        }

        $target = $budget;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            // Damp the ratio so an overshoot does not collapse the target to
            // nothing on the first step.
            $ratio = $ceiling / max(1.0, $bestTotal);
            $target *= max(0.80, min(0.98, $ratio));

            $candidate = $picker($target);

            if (! $this->isComplete($candidate['components'] ?? [], $allowMissingGpu)) {
                break;
            }

            $candidateTotal = $this->allInFor($candidate['components']);

            if ($candidateTotal < $bestTotal) {
                $best = $candidate;
                $bestTotal = $candidateTotal;
            }

            if ($wantModernPlatform && $this->attemptHasModernCpu($candidate)) {
                if ($modernBest === null || $candidateTotal < $modernBestTotal) {
                    $modernBest = $candidate;
                    $modernBestTotal = $candidateTotal;
                }

                if ($modernBestTotal <= $ceiling) {
                    return $modernBest;
                }
            }

            if ($bestTotal <= $ceiling) {
                break;
            }
        }

        // A modern build that fits beats a cheaper EOL-platform one.
        return $modernBest ?? $best;
    }

    /**
     * Is the CPU in this attempt's component list a modern-platform part?
     * Build component arrays carry name/price, not full Component models, so
     * the row is re-read for the authoritative socket test. One row per
     * attempt, and only on the attempts that matter - it is cheaper than the
     * mistake it prevents.
     */
    protected function attemptHasModernCpu(array $attempt): bool
    {
        $id = (int) ($attempt['components']['cpu']['id'] ?? 0);

        if ($id <= 0) {
            return false;
        }

        $cpu = Component::find($id);

        if ($cpu === null) {
            // Cannot prove it is modern, so it cannot satisfy a gate that
            // promises a modern platform. Fall through to the name test so a
            // readable catalogue row is not discarded on a lookup miss.
            return ! $this->isEntryPlatformCpuName(
                (string) ($attempt['components']['cpu']['name'] ?? '')
            );
        }

        return ! $this->isEntryPlatformCpu($cpu);
    }

    /**
     * Name-based twin of isEntryPlatformCpu() for build component arrays.
     */
    protected function isEntryPlatformCpuName(string $name): bool
    {
        $name = strtoupper($name);

        if (preg_match('/RYZEN\s+[3579]\s*[45]\d{3}[A-Z]*/', $name)) {
            return true;
        }

        if (preg_match('/CORE\s+I[57]\s*-?\s*1[23]\d{2,3}/', $name)) {
            return true;
        }

        return false;
    }

    /**
     * The price the customer actually sees for a component set.
     *
     * @param  array<string, mixed>  $components
     */
    protected function allInFor(array $components): float
    {
        return $this->pricing->completePrice((float) collect($components)->sum('price'));
    }

    /**
     * Pick the best-scoring components within a budget while guaranteeing the
     * cursor moves through every category. When a category has no candidate
     * inside its share it relaxes to the cheapest component in that pool so
     * the system can still be completed (spending may exceed the budget â€” the
     * decorate step explains that in plain English).
     */
    protected function pickBalancedBuild(array $pools, float $budget, bool $forceGpu, bool $needApu, ?string $resolution = null): array
    {
        $selection = [];
        $spent = 0.0;
        $slack = 0.0;
        $cpuSocket = null;
        $cpuBrand = null;

        // Measured 2026-09-28: deliberately picking against budget x 1.05 (the
        // ceiling the publish gate accepts) instead of the raw budget was
        // TRIED and REVERTED. It looked like the obvious fix for the picker and
        // the gate disagreeing about the target, but it made the fit point
        // worse - the dGPU lane stopped fitting its budget at GBP 1,950 and went
        // back to GBP 2,200, with mid-band overshoot rising from 15.6% to 18.8%
        // at a GBP 1,200 ask. A larger parts budget simply buys more expensive
        // early parts, and the reserve then has less to give the categories
        // that follow. The target stays at the customer's stated budget.
        $partsBudget = $this->pricing->partsBudgetFor($budget);
        $hardCap = $partsBudget * 0.95;

        $shares = [
            'cpu' => 0.30,
            'gpu' => 0.40,
            'ram' => 0.08,
            'storage' => 0.07,
            'motherboard' => 0.07,
            'psu' => 0.04,
            'case' => 0.07,
            'cooler' => 0.06,
        ];

        // Premium look on premium tiers (boss directive 2026-09-27): the case
        // is the first thing a buyer sees. An entry Â£800 build gets a sensible
        // Â£50 shroud case, but a Â£1,300+ build deserves a Â£120+ showcase case
        // (O11 Vision / H6 Flow class) â€” not whatever slack the CPU/GPU left
        // over. Kept explicit so the look is guaranteed at the top end.
        if ($budget >= 1300) {
            $shares['case'] = 0.12;
        }

        $debug = (bool) env('PCTG_PICK_DEBUG', false);
        if ($debug) {
            fwrite(STDERR, sprintf(
                "  [pick] forceGpu=%s budget=%.2f partsBudget=%.2f hardCap=%.2f\n",
                $forceGpu ? 'yes' : 'no', $budget, $partsBudget, $hardCap
            ));
        }

        // Socket-locked categories (motherboard, cooler) DEPEND on the CPU so
        // they must run in a second pass, after the CPU socket is known.
        // Category order in the DB is not guaranteed (cooler can sort first).
        //
        // Budget reservation for the parts we have not chosen yet.
        //
        // The hard cap used to be checked only against what had been spent SO
        // FAR, which meant an expensive early pick (the GPU, or the CPU) could
        // swallow the cap and leave nothing for the categories that follow. The
        // trace for a GBP 1,000 ask is the clearest example: the GPU took
        // GBP 290 of a GBP 636 cap, then the RAM step had GBP 196 left to play
        // with while the cheapest legal 32GB+ kit costs GBP 215.94. The
        // budgeted candidate list came back empty and the relax-to-cheapest
        // fallback - which ignores the cap - supplied the memory anyway,
        // producing a GBP 898 parts total against a GBP 636 cap.
        //
        // So before choosing any part we reserve the cheapest legal part for
        // every category still to come. The GPU can then only have what is
        // genuinely left after the build is guaranteed to be finishable.
        //
        // The reserve is deliberately the cheapest part in the whole category
        // rather than the cheapest socket-compatible one, because the socket is
        // not known until the CPU is picked. That makes the reserve larger than
        // strictly necessary, which errs towards a slightly conservative build
        // - the right direction when the alternative is a quote that cannot be
        // honoured.
        $floorCosts = [];
        foreach ($pools as $poolSlug => $pool) {
            $min = $pool
                ->filter(fn (Component $c) => (float) $c->price > 0)
                ->min(fn (Component $c) => (float) $c->price);
            $floorCosts[$poolSlug] = (float) ($min ?? 0.0);
        }

        $ordered = array_merge(
            array_values(array_diff(array_keys($pools), ['motherboard', 'cooler'])),
            ['motherboard', 'cooler']
        );

        foreach ($ordered as $slug) {
            $pool = $pools[$slug] ?? collect();

            if ($pool->isEmpty()) {
                continue;
            }

            // APU mode has no GPU slot.
            if (! $forceGpu && $slug === 'gpu') {
                continue;
            }

            // Apply the quality floor so junk catalogue entries never ship.
            $pool = $this->viablePool($slug, $pool, $cpuBrand);

            // PSU floor (boss directive 2026-09-27): never ship below the
            // resolution minimum (750W on 1080p, 850W on 1440p, 1000W on 4K)
            // and never below the GPU's own hardware requirement (checked
            // against manufacturer settings â€” e.g. an RX 9070 XT needs min
            // 750W, an RTX 5080 needs 850W). Applied to the POOL, so every
            // fallback path (relax-to-cheapest, hard-cap pass) still meets it.
            if ($slug === 'psu') {
                $psuFloor = $this->minPsuFor($resolution, $selection['gpu'] ?? null);
                $pool = $pool->filter(fn (Component $component) => $this->psuWattage($component) >= $psuFloor);

                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // APU mode restricts the CPU to parts with built-in graphics.
            if ($needApu && $slug === 'cpu') {
                $pool = $pool->filter(fn (Component $component) => $this->hasIntegratedGraphics($component));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // Dedicated-GPU mode is the mirror image: the processor must be
            // good enough to feed the card, and must NOT be an APU, because a
            // processor's own graphics do nothing once a discrete card is
            // fitted. Applied to the POOL so every fallback below (spendable
            // pass, relax-to-cheapest) still meets it â€” a rule that only the
            // happy path honours is not a rule.
            // Mirror gate (boss complaint 2026-10-06): above the entry
            // ceiling, the pool must NOT contain a 2020-era platform CPU.
            // Previously the entry-platform rule only narrowed the pool below
            // GBP 1,300 - at or above it the scorer was free to land on a
            // Ryzen 5 4500 + A520M + DDR4 build, which is exactly the
            // shocking-system-for-the-money the Boss flagged at GBP 1,700.
            // Below the ceiling nothing changes: AM4/LGA1700 is still the
            // honest choice when the money only stretches that far.
            if ($slug === 'cpu' && $forceGpu && $budget >= self::ENTRY_PLATFORM_CEILING) {
                $modernPool = $pool->reject(fn (Component $c) => $this->isEntryPlatformCpu($c));
                if ($modernPool->isNotEmpty()) {
                    $pool = $modernPool;
                }
            }

            if ($slug === 'cpu' && $budget < self::ENTRY_PLATFORM_CEILING) {
                // Entry-platform rule (boss directive 2026-09-28). Only bites
                // below GBP 1,300; at or above that the customer's money buys
                // a better platform and we let the scorer decide. If the
                // catalogue has nothing qualifying we keep the full pool
                // rather than refuse to build.
                //
                // The APU lane needs the extra iGPU condition. Ryzen 5000
                // DESKTOP silicon has no integrated graphics, so the plain
                // entry-platform list cannot drive a display and a GPU-less
                // machine built from it would be a black screen. The only
                // Ryzen 5000 parts in the catalogue with an iGPU are the 5600G
                // and 5700G, and the 5600G is the honest entry processor for
                // this lane: Ryzen 5000, six cores, integrated graphics, and
                // it is the part that actually satisfies the directive instead
                // of quietly shipping a Ryzen 3 3200G and calling the machine
                // compliant.
                $entryPool = $forceGpu
                    ? $pool->filter(fn (Component $c) => $this->isEntryPlatformCpu($c))
                    : $pool->filter(fn (Component $c) => $this->isEntryPlatformCpu($c)
                        && $this->hasIntegratedGraphics($c));

                if ($entryPool->isNotEmpty()) {
                    $pool = $entryPool;
                }
            }

            if ($forceGpu && $slug === 'cpu') {
                $pool = $pool->filter(fn (Component $component) => $this->meetsDgpuCpuStandard($component));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // The graphics card must be honest for the resolution that was
            // actually asked for. Without this a GBP 1,400 4K request was
            // answered with a 6GB Intel Arc A380 â€” a 1080p card in a machine
            // described as 4K, which is exactly the oversell that costs a
            // 5-star review. Applied to the POOL for the same reason as the
            // CPU rule above.
            if ($forceGpu && $slug === 'gpu') {
                $pool = $pool->filter(fn (Component $component) => $this->gpuMeetsResolution($component, $resolution));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // RAM generation must match the CPU platform. The CPU is always
            // picked first in the loop, so the socket is known here; a real
            // motherboard that fits that socket inherently uses the same RAM
            // generation (AM4=DDR4, AM5=DDR5, LGA1700/1851=the platform's gen).
            if ($slug === 'ram' && $cpuSocket !== null) {
                $ramGen = $this->ramGenerationForSocket($cpuSocket);
                if ($ramGen !== null) {
                    $pool = $pool->filter(fn (Component $component) => $this->ramGenerationMatches($component, $ramGen));
                    if ($pool->isEmpty()) {
                        continue;
                    }
                }
            }

            // The allowance is the larger of the category's budget share and
            // the cheapest part that can legally go in this slot.
            //
            // The share alone is not a workable constraint any more. These
            // shares were calibrated when a 32GB kit was around GBP 120 and a
            // 500GB SSD around GBP 35, so RAM was given 8% and storage 7%. In
            // the 2026 shortage a mandatory 32GB+ kit is GBP 216-450 and a
            // 500GB+ drive is GBP 58-100, which means the share allowance for
            // those two categories was BELOW the price of the cheapest part
            // allowed by our own spec floor. The budgeted candidate list
            // therefore came back empty on every single build, the
            // relax-to-cheapest fallback fired, and it picked the real part
            // while ignoring both the allowance and the GBP 636 hard cap -
            // which is how a GBP 1,000 ask came back at GBP 1,235.
            //
            // Funding the floor first, and letting the share control only the
            // discretionary uplift above it, keeps the pick inside the cap
            // whenever the budget genuinely can afford the spec. When even the
            // floors do not fit, the build is honestly over budget and the
            // publish gate says so, which is the truthful answer rather than a
            // fabricated one caused by a miscalibrated percentage.
            $floorPrice = (float) ($pool->min(fn (Component $component) => (float) $component->price) ?? 0);
            $allow = max($partsBudget * ($shares[$slug] ?? 0.05), $floorPrice) + $slack;

            // What every still-unpicked category needs at minimum. Without this
            // the cap is only ever measured against money already committed,
            // and the first expensive pick (normally the GPU) eats the whole
            // budget and starves the memory, storage, board, case and cooler
            // steps that follow it.
            $reserve = 0.0;
            $position = array_search($slug, $ordered, true);
            if ($position !== false) {
                foreach (array_slice($ordered, (int) $position + 1) as $later) {
                    if (! $forceGpu && $later === 'gpu') {
                        continue;
                    }
                    $reserve += $floorCosts[$later] ?? 0.0;
                }
            }

            $spendable = max(0.0, $hardCap - $spent - $reserve);

            $candidates = $pool
                ->filter(fn (Component $component) => (float) $component->price <= min($allow, $spendable) + 0.001);

            if ($this->socketLocked($slug)) {
                if ($cpuSocket === null) {
                    continue;
                }

                $socketFits = fn (Component $component) => $this->fitsSocket($slug, $component, $cpuSocket);

                $pick = $candidates
                    ->filter($socketFits)
                    ->sortByDesc('score')
                    ->first();

                if ($pick === null) {
                    // No in-budget candidate that physically fits â€” relax to
                    // the cheapest compatible part so the system can still
                    // complete. For coolers, prefer the cheapest compatible
                    // AIO; only drop to air/cheapest when no AIO can
                    // physically fit (2026-09-27 boss directive). This branch
                    // already accepts an over-budget build (decorateBuild
                    // explains it honestly), so the AIO is NOT hard-cap
                    // gated â€” an honest Â£31 AIO beats a Â£7.50 air tower.
                    if ($slug === 'cooler') {
                        $pick = $pool
                            ->filter($socketFits)
                            ->filter(fn (Component $component) => $this->isAioCooler($component))
                            ->sortBy('price')
                            ->first();
                    }

                    if ($pick === null) {
                        $pick = $pool
                            ->filter($socketFits)
                            ->sortBy('price')
                            ->first();
                    }
                }
            } else {
                // PSU is a grudge-buy (boss directive 2026-09-27): the floor
                // filter above already guarantees the hardware minimum and a
                // pricier PSU adds zero fps, so the CHEAPEST compliant unit is
                // the best value â€” never let accumulated slack hoover into a
                // Â£105 unit on an Â£800 rig. The dream build keeps the premium
                // score path (pickIdealBuild) where a flagship PSU belongs.
                if ($slug === 'psu') {
                    $pick = $pool->sortBy('price')->first();
                } elseif ($slug === 'case' && $budget >= 1300) {
                    // Premium-look case on premium tiers (boss directive
                    // 2026-09-27): the case is the visual anchor of a Â£1,300+
                    // build. Pick the best-scoring showcase case from the full
                    // acceptable pool (O11 Vision / H6 Flow class) even if it
                    // runs past the share allowance â€” the score premium makes
                    // the O11 beat the Â£50 Montech once we stop gating on the
                    // allowance, and decorateBuild reports the honest total.
                    $pick = $candidates->sortByDesc('score')->first();

                    if ($pick === null || $this->casePremiumBonus($pick) < 30) {
                        $pick = $pool->sortByDesc('score')->first();
                    }
                } elseif ($slug === 'gpu' && $forceGpu) {
                    // Value-first graphics card (Genie 2026-09-28).
                    //
                    // The pool is ALREADY filtered to cards that genuinely
                    // meet the requested resolution, so every card in it
                    // honours the promise we make in the quote. The score
                    // premium therefore only decides how much of the rest of
                    // the machine gets funded - and on the 4K band that
                    // difference was pure waste: the picker took the
                    // RX 9070 GRE at GBP 503.94 when the equally 4K-capable
                    // RX 7700 XT at GBP 407.83 was sitting in the same pool,
                    // adding GBP 96 of nothing to a build that was already
                    // over the customer's budget. A customer does not get
                    // 3 extra fps they will never see; they get a quote they
                    // can actually afford.
                    //
                    // Premium cards still win when the money is genuinely
                    // there. We only demote when the premium pick would
                    // leave the categories that follow unable to be funded,
                    // because at that point the build is already over budget
                    // and every extra pound of graphics card makes the honest
                    // overage worse.
                    $pick = $candidates->sortByDesc('score')->first();

                    if ($pick === null) {
                        $pick = $pool->sortByDesc('score')->first();
                    }

                    if ($pick !== null && $spent + (float) $pick->price + $reserve > $hardCap) {
                        $cheapestCompliant = $pool->sortBy('price')->first();

                        if ($cheapestCompliant !== null
                            && (float) $cheapestCompliant->price < (float) $pick->price) {
                            $pick = $cheapestCompliant;
                        }
                    }
                } else {
                    $pick = $candidates->sortByDesc('score')->first();

                    // Degrade inside the cap before giving up on it: the cap
                    // now includes the reserve for later categories, so this
                    // second attempt is the "cheapest part that still leaves
                    // the rest of the build fundable" case.
                    if ($pick === null) {
                        $pick = $pool
                            ->filter(fn (Component $component) => (float) $component->price <= $spendable + 0.001)
                            ->sortByDesc('score')
                            ->first();
                    }

                    // Genuine last resort: the cheapest part in the category
                    // regardless of budget. Reaching this branch means the
                    // spec floors and the stated budget are not simultaneously
                    // satisfiable, which is an honest outcome - the publish
                    // gate reports the overage rather than hiding it.
                    if ($pick === null) {
                        $pick = $pool->sortBy('price')->first();
                    }
                }
            }

            if ($pick === null) {
                if ($forceGpu && $slug === 'gpu') {
                    return ['components' => $selection, 'complete' => false];
                }

                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
                // Brand + chipset for GPU (and chipset where known) so customer
                // specs show the full model, not just the partner card name
                // (2026-09-27 boss directive: "list gpu brand and model").
                'chipset' => $pick->chipset ?? null,
                // Internal-only wattage used by the power-adequacy pass so a
                // GPU's real draw can never be paired with an undersized PSU.
                'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true) ? $this->componentWattage($slug, $pick) : 0,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $this->canonicalSocket($pick);
                $cpuBrand = $this->cpuBrandOf(['name' => $pick->name]);
            }

            $spent += (float) $pick->price;
            $slack = max(0.0, $allow - (float) $pick->price);

            if ($debug) {
                fwrite(STDERR, sprintf(
                    "    %-12s allow %8.2f  picked %-34s %8.2f  spent %8.2f  %s [spendable %.2f reserve %.2f hardCap %.2f cands %d]\n",
                    $slug, $allow, mb_substr((string) $pick->name, 0, 34),
                    (float) $pick->price, $spent,
                    $spent > $hardCap ? '<< OVER HARD CAP' : '',
                    $spendable, $reserve, $hardCap, $candidates->count()
                ));
            }
        }

        $prePower = $selection;
        $selection = $this->ensurePowerAdequacy($selection, $pools, $resolution);
        $spent = (float) collect($selection)->sum('price');

        // --- Post-loop trim: bring a capped build back inside the cap -----
        //
        // The per-step relax-to-cheapest fallbacks deliberately ignore the
        // cap, because a half-built machine is worth nothing. That is right
        // as a last resort, but it means a build that overran picks its
        // PREMIUM parts at full price and only discovers the overshoot once
        // every category is already committed. On the published 1440p and 4K
        // bands that put GBP 78-108 over the customer's budget on 8 of 20
        // in-band asks, which is a real quote we then had to refuse.
        //
        // So once the machine is complete we walk the discretionary parts
        // (GPU first, then CPU) and step each one down to the cheapest
        // variant that still clears the spec floors, until the parts total
        // fits the hard cap or there is nothing left to give. The GPU is
        // tried first because it carries the largest single chunk of
        // discretionary spend and because the resolution floor is a hard
        // promise - the trim will never drop a 4K build below a genuine 4K
        // card, it only gives back the tier premium above that floor.
        // CPU/GPU coherence, run AFTER both parts are known. The picker takes
        // the processor before the card, so the floor cannot be applied during
        // selection - it has to be reconciled once the pair exists. We prefer
        // to fix a starved processor by upgrading it, because that is what the
        // customer's money was for; only if the cap cannot fund a coherent CPU
        // do we step the card down to a tier the processor can actually drive.
        //
        // ORDER MATTERS: this runs BEFORE trimToCap() so the trim gets the
        // last word on money. Running it afterwards re-broke the 4K floor
        // (a coherent-CPU upgrade at GBP 1,850 pushed the quote GBP 112 over
        // with nothing left to give back), which is precisely the trade we
        // refuse to make - a machine we can afford beats a matched one we
        // cannot.
        $selection = $this->enforceCpuGpuCoherence($selection, $pools, $hardCap, $resolution, $debug);
        $selection = $this->ensurePowerAdequacy($selection, $pools, $resolution);

        $spent = $this->trimToCap($selection, $pools, $hardCap, $resolution, $forceGpu, $debug);
        $selection = $this->trimmedSelection;
        $selection = $this->ensurePowerAdequacy($selection, $pools, $resolution);
        $spent = (float) collect($selection)->sum('price');

        // --- Post-trim slack: now buy everything the money can still buy ----
        //
        // trimToCap() only ever moves DOWNWARDS, so a build that finished
        // comfortably under the cap is left there. Measured 2026-10-07 on the
        // published bands: GBP 2,500/1440p came back at GBP 2,386 with a
        // GeForce RTX 5060 Ti - the same card as the GBP 1,700 build, for
        // GBP 800 more. The customer paid the upgrade money and received the
        // cheaper machine twice.
        //
        // Cause: every stage maximises a score INSIDE a per-category cap and
        // then stops. Nothing anywhere had an owner for "money still left".
        //
        // This runs LAST, on the finished machine, so it sees the real total
        // and the real remaining headroom, and so nothing downstream can undo
        // it. It is deliberately the mirror image of trimToCap(): each step
        // only ever accepts a STRICTLY better part that still fits under the
        // hard cap. It cannot lower a build's capability, cannot breach the
        // budget, and cannot drop the resolution floor (a GPU tier may only
        // ever go up).
        $selection = $this->spendSlack($selection, $pools, $hardCap, $forceGpu, $debug);
        $selection = $this->ensurePowerAdequacy($selection, $pools, $resolution);
        $spent = (float) collect($selection)->sum('price');

        if ($debug) {
            $added = $spent - (float) collect($prePower)->sum('price');
            fwrite(STDERR, sprintf(
                "    power-adequacy changed parts: %s  (parts %.2f -> %.2f, hardCap %.2f)\n",
                abs($added) < 0.005 ? 'no' : 'YES +'.number_format($added, 2),
                (float) collect($prePower)->sum('price'), $spent, $hardCap
            ));
        }

        return [
            'components' => $selection,
            'total' => $this->pricing->completePrice($spent),
            'remaining' => round(max(0, $budget - $this->pricing->completePrice($spent)), 2),
        ];
    }

    /**
     * The most cost-effective fully working system in the catalogue: cheapest
     * CPU with built-in graphics (APU), the cheapest compatible motherboard
     * and the cheapest part in every other category. No dedicated GPU â€” the
     * CPU's integrated graphics drive the display. Used when a budget cannot
     * complete even an APU build.
     */
    protected function pickCheapestComplete(array $pools, ?string $resolution = null): ?array
    {
        $selection = [];
        $spent = 0.0;
        $cpuSocket = null;
        $cpuBrand = null;

        foreach ($pools as $slug => $pool) {
            if ($pool->isEmpty()) {
                continue;
            }

            if ($slug === 'gpu') {
                continue; // Cheapest APU build has no dedicated GPU.
            }

            if ($slug === 'cpu') {
                $pool = $pool->filter(fn (Component $component) => $this->hasIntegratedGraphics($component));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            $pool = $this->viablePool($slug, $pool, $cpuBrand);

            // PSU floor (boss directive 2026-09-27): even the cheapest system
            // must respect the resolution minimum (750W/850W/1000W). This
            // build has no dedicated GPU so the GPU minimum does not apply â€”
            // but the resolution floor always does.
            if ($slug === 'psu') {
                $psuFloor = $this->minPsuFor($resolution, null);
                $pool = $pool->filter(fn (Component $component) => $this->psuWattage($component) >= $psuFloor);

                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // RAM generation must match the CPU platform (AM4=DDR4, AM5=DDR5,
            // LGA1700/1851=that platform's generation ring).
            if ($slug === 'ram' && $cpuSocket !== null) {
                $ramGen = $this->ramGenerationForSocket($cpuSocket);
                if ($ramGen !== null) {
                    $pool = $pool->filter(fn (Component $component) => $this->ramGenerationMatches($component, $ramGen));
                    if ($pool->isEmpty()) {
                        continue;
                    }
                }
            }

            $pick = $pool->sortBy('price')->first();

            if ($slug === 'motherboard' && $cpuSocket !== null) {
                $fitting = $pool
                    ->filter(fn (Component $component) => $component->socket === $cpuSocket)
                    ->sortBy('price')
                    ->first();

                if ($fitting !== null) {
                    $pick = $fitting;
                }
            }

            if ($pick === null) {
                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
                'chipset' => $pick->chipset ?? null,
                'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true) ? $this->componentWattage($slug, $pick) : 0,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $this->canonicalSocket($pick);
                $cpuBrand = $this->cpuBrandOf(['name' => $pick->name]);
            }

            $spent += (float) $pick->price;
        }

        if ($selection === []) {
            return null;
        }

        $selection = $this->ensurePowerAdequacy($selection, $pools, $resolution);
        $spent = (float) collect($selection)->sum('price');

        return [
            'components' => $selection,
            'total' => $this->pricing->completePrice($spent),
            'remaining' => 0.0,
        ];
    }

    /**
     * The honest entry price for a resolution: the cheapest COMPLETE, buildable
     * machine we can genuinely assemble and sell to that standard.
     *
     * Boss directive 2026-09-28: advertise only bands we can actually deliver,
     * and start the budget slider at a number that really does produce a
     * machine. A band floor typed in by hand drifts from the catalogue within
     * days of a price refresh and quietly becomes a promise we cannot keep, so
     * this MEASURES the floor from live parts every time it is asked.
     *
     * This is deliberately NOT the same as calling recommend() at a low budget.
     * A low budget makes the picker overshoot to reach its target, which is a
     * quote, not a build the customer could actually buy for their money. The
     * slider has to start where a real build starts.
     *
     * The result is memoised per resolution for the process lifetime: this runs
     * the whole picker, and the storefront asks for it on every page load.
     *
     * @return array{price: float, rounded: float, cpu: string|null, gpu: string|null, complete: bool}|null
     */
    public function entryPriceFor(?string $resolution): ?array
    {
        $key = strtoupper(trim((string) $resolution));

        if ($key === '') {
            $key = 'default';
        }

        if (array_key_exists($key, $this->entryPriceCache)) {
            return $this->entryPriceCache[$key];
        }

        $pools = $this->buildPools(
            Category::query()->orderBy('id')->get(),
            'gaming',
            $resolution
        );

        $build = $this->cheapestCompleteDgpuBuild($this->dedicatedGpuPools($pools), $resolution);

        if ($build === null) {
            return $this->entryPriceCache[$key] = null;
        }

        $total = round((float) $build['total'], 2);

        $complete = $this->isComplete($build['components']);

        // Round UP to the nearest GBP 10 for the number we publish. Rounding
        // down would advertise a band floor we cannot actually build to;
        // rounding up keeps the promise we make.
        $rounded = ceil($total / 10) * 10;

        return $this->entryPriceCache[$key] = [
            'price' => $total,
            'rounded' => $rounded,
            'cpu' => $build['components']['cpu']['name'] ?? null,
            'gpu' => $build['components']['gpu']['name'] ?? null,
            'complete' => $complete,
        ];
    }

    /**
     * Cheapest COMPLETE machine with a discrete graphics card, honouring every
     * floor a real build must clear: consumer GPU, band GPU tier, band VRAM,
     * six-core non-APU processor, socket-matched board, platform-matched RAM,
     * PSU wattage and the cooling rule.
     *
     * This is the machine the entry price is measured from, so it deliberately
     * mirrors the real dGPU lane rather than taking a shortcut: an entry price
     * derived from a looser build would be a number we cannot honour.
     *
     * @param  array<string, mixed>  $pools
     * @return array{components: array<string, array>, total: float}|null
     */
    protected function cheapestCompleteDgpuBuild(array $pools, ?string $resolution = null): ?array
    {
        $selection = [];
        $spent = 0.0;
        $cpuSocket = null;
        $cpuBrand = null;

        $gpuTierFloor = $this->minGpuTierFor($resolution);
        $gpuVramFloor = $this->minGpuVramFor($resolution);

        // The CPU and the GPU are chosen TOGETHER, not greedily one after the
        // other.
        //
        // A greedy "cheapest processor, then cheapest card" pass happily pairs a
        // six-core chip with a tier-5 4K card - the very combination
        // GPU_CPU_COHERENCE exists to forbid. The first version of this method
        // did exactly that and reported a GBP 1,666 "4K" machine built on a
        // 2019 six-core Ryzen 5 4500: a build the real engine would refuse, and
        // therefore a number we could not honestly advertise. Picking the pair
        // jointly means the measured floor is the cheapest build the engine
        // would actually be willing to quote.
        $cpuPool = $this->viablePool('cpu', $pools['cpu'] ?? collect())
            ->filter(fn (Component $c) => $this->meetsDgpuCpuStandard($c)
                && ! $this->hasIntegratedGraphics($c))
            // Cheapest FIRST, not catalogue order: `first()` on an unsorted pool
            // returned whatever the query happened to yield first, which put a
            // Ryzen 7 9800X3D in a GBP 1,300 1080p machine.
            ->sortBy('price')
            ->values();

        $gpuPool = $this->viablePool('gpu', $pools['gpu'] ?? collect())
            ->filter(fn (Component $c) => $this->isConsumerGpu($c)
                && $this->gpuPerformanceTier($c) >= $gpuTierFloor
                && $this->gpuVramGb($c) >= $gpuVramFloor);

        $bestPair = null;

        foreach ($gpuPool as $candidateGpu) {
            $feasibleCpu = $cpuPool->first(
                fn (Component $c) => $this->cpuCanFeedGpu($c, $candidateGpu)
            );

            if ($feasibleCpu === null) {
                continue;
            }

            $cost = (float) $feasibleCpu->price + (float) $candidateGpu->price;

            if ($bestPair === null || $cost < $bestPair['cost']) {
                $bestPair = ['cost' => $cost, 'cpu' => $feasibleCpu, 'gpu' => $candidateGpu];
            }
        }

        if ($bestPair === null) {
            return null;
        }

        // Seed the selection with the coherent pair, then fill the rest.
        $pools['cpu'] = collect([$bestPair['cpu']]);
        $pools['gpu'] = collect([$bestPair['gpu']]);

        foreach (['cpu', 'motherboard', 'ram', 'storage', 'gpu', 'psu', 'case', 'cooler'] as $slug) {
            $pool = $pools[$slug] ?? collect();

            if ($pool->isEmpty()) {
                continue;
            }

            $pool = $this->viablePool($slug, $pool, $cpuBrand);

            if ($slug === 'cpu') {
                $pool = $pool->filter(
                    fn (Component $c) => $this->meetsDgpuCpuStandard($c)
                        && ! $this->hasIntegratedGraphics($c)
                );
            }

            if ($slug === 'gpu') {
                $pool = $pool->filter(
                    fn (Component $c) => $this->isConsumerGpu($c)
                        && $this->gpuPerformanceTier($c) >= $gpuTierFloor
                        && $this->gpuVramGb($c) >= $gpuVramFloor
                );
            }

            if ($slug === 'psu') {
                $floor = $this->minPsuFor($resolution, null);
                $pool = $pool->filter(fn (Component $c) => $this->psuWattage($c) >= $floor);
            }

            if ($slug === 'ram' && $cpuSocket !== null) {
                $gen = $this->ramGenerationForSocket($cpuSocket);
                if ($gen !== null) {
                    $pool = $pool->filter(fn (Component $c) => $this->ramGenerationMatches($c, $gen));
                }
            }

            $pick = $pool->sortBy('price')->first();

            if ($slug === 'motherboard' && $cpuSocket !== null) {
                $fitting = $pool->filter(fn (Component $c) => $c->socket === $cpuSocket)
                    ->sortBy('price')->first();
                $pick = $fitting ?? $pick;
            }

            if ($pick === null) {
                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
                'chipset' => $pick->chipset ?? null,
                'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true)
                    ? $this->componentWattage($slug, $pick) : 0,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $this->canonicalSocket($pick);
                $cpuBrand = $this->cpuBrandOf(['name' => $pick->name]);
            }

            $spent += (float) $pick->price;
        }

        if ($selection === [] || ! $this->isComplete($selection)) {
            return null;
        }

        $selection = $this->ensurePowerAdequacy($selection, $pools, $resolution);
        $spent = (float) collect($selection)->sum('price');

        if (! $this->isComplete($selection)) {
            return null;
        }

        return [
            'components' => $selection,
            'total' => $this->pricing->completePrice($spent),
        ];
    }

    /**
     * The workable price bands, measured and ready for the storefront.
     *
     * `min` is the real measured floor (never the hand-typed guess), `max` is
     * the published ceiling. The slider, the clamp and the copy all read this
     * one array so the number a customer sees and the number we can build to
     * cannot disagree.
     *
     * @return array<string, array{min: float, max: float, label: string, floor_gpu_tier: int, measured: bool}>
     */
    public function workableBands(): array
    {
        $out = [];

        foreach (self::RESOLUTION_BANDS as $key => $band) {
            $measured = $this->entryPriceFor($key);

            $out[$key] = [
                'min' => $measured !== null && $measured['complete']
                    ? (float) $measured['rounded']
                    : (float) $band['min'],
                'max' => (float) $band['max'],
                'label' => $band['label'],
                'floor_gpu_tier' => (int) $band['floor_gpu_tier'],
                'measured' => $measured !== null && $measured['complete'],
            ];
        }

        return $out;
    }

    /**
     * The WhatsApp number customers are offered when they cannot reach a
     * brand-new build (boss directive 2026-09-28).
     */
    public const WHATSAPP_NUMBER = '+447933101083';

    /**
     * What the customer is told, and shown, when their budget is below the
     * price of a real machine.
     *
     * Boss directive 2026-09-28: if someone types a budget lower than the
     * cheapest build we can honestly make, the input moves itself to that
     * minimum and an on-screen note explains why - in plain English, not
     * jargon - and offers a part-new/part-used build with a WhatsApp call to
     * action.
     *
     * The wording matters more than it looks. "Budget below minimum" is not an
     * explanation, it is a rejection. A customer who came here with GBP 900
     * has not done anything wrong, and telling them plainly that a complete
     * new machine starts at GBP 1,260 - and that we can mix in parts we have
     * already checked to bring it down - is how you keep the sale.
     *
     * @return array{min: float, floor: float, raised: bool, message: string, hybrid: array}|null
     */
    public function budgetNotice(float $budget, ?string $resolution = null): ?array
    {
        $bands = $this->workableBands();
        $key = $this->bandKeyFor($resolution);

        // No band for this resolution: nothing to clamp against, so say nothing
        // rather than invent a floor.
        if ($key === null) {
            return null;
        }

        $band = $bands[$key];
        $floor = (float) $band['min'];
        $asked = round(max(0.0, $budget), 2);

        $money = fn (float $v): string => '£'.number_format($v, 0);

        return [
            'min' => $floor,
            'floor' => $asked,
            'raised' => $asked > 0 && $asked < $floor,
            'label' => $band['label'],
            'message' => $asked > 0 && $asked < $floor
                ? sprintf(
                    'We have moved your budget to %s, which is the least a brand-new '
                    .'%s machine costs when it comes to us fully built, tested and covered '
                    .'by our two-year warranty. Below that we would have to leave something '
                    .'out - the memory, the proper cooling, or the graphics card - and we '
                    .'would rather be straight with you than hand you a PC we would not '
                    .'put our name on.',
                    $money($floor),
                    strtolower($band['label'])
                )
                : '',
            'hybrid' => [
                'available' => true,
                'headline' => 'Want to spend less?',
                'message' => 'We can mix brand-new parts with parts we have already '
                    .'checked, tested and graded, which brings the price down while '
                    .'keeping the warranty on the whole machine. It is not something we '
                    .'put on the website, so give us a ring and we will price one up for you.',
                'whatsapp' => self::WHATSAPP_NUMBER,
                'whatsapp_url' => 'https://wa.me/'.preg_replace('/[^0-9]/', '', self::WHATSAPP_NUMBER),
            ],
        ];
    }

    /**
     * The RESOLUTION_BANDS key for a resolution, or null when we publish no
     * band for it. Split out from bandFor() so the clamp can tell "unknown
     * resolution" from "band with a floor".
     */
    public function bandKeyFor(?string $resolution): ?string
    {
        $res = strtoupper(trim((string) $resolution));

        return match (true) {
            $res === '' => null,
            str_contains($res, '4K'), str_contains($res, '2160'), str_contains($res, 'UHD') => '4k',
            str_contains($res, '1440'), str_contains($res, 'QHD'), str_contains($res, '2K') => '1440p',
            str_contains($res, '1080'), str_contains($res, 'FHD') => '1080p',
            default => null,
        };
    }

    /**
     * Add every explainer a displayed build needs: one complete price, mode,
     * APU warning, cheapest-option flag and a plain-English pricing note tied
     * to supply and demand when a budget can't stretch to a complete system.
     */
    protected function decorateBuild(array $build, float $budget, string $mode, bool $apuWarning, ?string $resolution = null): array
    {
        $components = $build['components'] ?? [];
        $spent = (float) collect($components)->sum('price');
        $total = $this->pricing->completePrice($spent);
        $overBudget = $budget > 0 ? round(max(0.0, $total - $budget), 2) : 0.0;

        $explanation = null;

        if ($overBudget > 0) {
            // Be honest about WHY. Below roughly the price of our minimum
            // standard build the limiting factor is our own PCTG spec floor
            // (32GB of memory, correct cooling, a shrouded case and a
            // two-year warranty included), not "graphics card prices". Saying
            // the latter when the former is true would be a false excuse for
            // not meeting the budget.
            //
            // The entry machine needs its own wording: it has no separate
            // graphics card, and describing it with the dedicated-GPU copy
            // would oversell it. It is a real gaming PC - the processor drives
            // the display itself - just not a 1440p one.
            $explanation = $mode === 'apu'
                ? 'This is our entry build. It has no separate graphics card - the '
                    . 'processor drives the display itself - so it is a genuine 1080p '
                    . 'gaming PC rather than a 1440p one, and we will not pretend otherwise. '
                    . 'You still get our full standard: 32GB of memory, air cooling '
                    . 'sized for that chip, a case with a power-supply shroud, and a '
                    . 'two-year warranty with the build and testing included. It comes to '
                    . 'GBP ' . number_format($total, 2) . ' against your budget of GBP '
                    . number_format($budget) . ', and it is the cheapest machine we can '
                    . 'honestly build to that standard. Add a graphics card later if you '
                    . 'want 1440p, or spread the cost with PayPal Pay in 3, interest-free.'
                // Resolution-floor overshoot (2026-09-28). At 1440p and 4K the
                // reason a build costs more than the budget is almost always
                // the graphics card, not the memory or the case, and the
                // generic copy below said otherwise. Telling a customer their
                // GBP 1,250 "4K" machine is GBP 1,666 because of 32GB of memory
                // would be plainly untrue - it is because a real 4K card costs
                // more than a 1080p one, and we will not fit the cheap card
                // and call it 4K.
                : ($this->minGpuTierFor($resolution) > 0
                    ? 'A genuine ' . strtoupper((string) $resolution) . ' machine needs a much '
                        . 'stronger graphics card than a 1080p one, and that is where the money '
                        . 'goes. We will not fit a 1080p card and call it '
                        . strtoupper((string) $resolution) . ', so this is the smallest build we '
                        . 'can honestly put together at that resolution: a real '
                        . strtoupper((string) $resolution) . ' graphics card, a processor that '
                        . 'can feed it, 32GB of memory, liquid cooling, a case with a '
                        . 'power-supply shroud, and a two-year warranty with the build and '
                        . 'testing included. It comes to GBP ' . number_format($total, 2)
                        . ' against your budget of GBP ' . number_format($budget) . '. If you '
                        . 'would rather spend closer to your budget, ask us for a 1080p build '
                        . 'instead - we will happily quote one - or spread the cost with PayPal '
                        . 'Pay in 3, interest-free.'
                    : 'Our standard on every PCTechGuy build is 32GB of memory, '
                        . 'proper cooling, a case with a power-supply shroud, and a two-year '
                        . 'warranty with the build and testing included. That standard costs '
                        . 'more than a budget of GBP ' . number_format($budget)
                        . ', so we will not pretend we can hit it and quietly send you a '
                        . 'weaker machine. What you see here is the cheapest build that still '
                        . 'meets our standard - it is the best value we can honestly do today, '
                        . 'and it is a proper gaming PC, not a paperweight. You can spread the '
                        . 'cost with PayPal Pay in 3, interest-free.');
        }

        return [
            'components' => $components,
            'total' => $total,
            'remaining' => round(max(0, $budget - $total), 2),
            'complete' => true,
            'mode' => $mode,
            'apuWarning' => $apuWarning,
            'cheapestOption' => $overBudget > 0,
            'overBudget' => $overBudget,
            'budget' => $budget,
            'explanation' => $explanation,
            'payments' => ['card', 'paypal_pay_in_3'],
            // Snapshot the floor state of THIS run. recommendBoth() builds
            // pools twice (budget then ideal), and the second pass resets the
            // property, so the gate must not read it back later.
            'floorDegradations' => $this->floorDegradations(),
        ];
    }

    /**
     * Attach the QC gate every quoting/marketing surface must respect before a
     * price is shown to a customer: how fresh the prices are, whether any spec
     * floor had to be relaxed, and whether the build is genuinely complete.
     *
     * `publishable` is deliberately false on an unverified build â€” the
     * catalogue is scraped, not a live retailer feed, so a build can be
     * technically excellent and still not be safe to quote.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function attachPublishGate(array $result): array
    {
        $components = $result['components'] ?? [];
        $freshness = $this->priceIntegrity->buildFreshness($components);
        $degradations = $result['floorDegradations'] ?? $this->floorDegradations();
        $blockers = [];

        if (($result['complete'] ?? false) !== true) {
            $blockers[] = 'Build is missing a required part, so it cannot be quoted.';
        }

        if (! $freshness['publishable']) {
            $blockers[] = 'Prices need checking against a live UK retailer before publishing (oldest is '
                . $freshness['oldest_days'] . ' day(s) old).';
        }

        foreach ($degradations as $slug => $note) {
            $blockers[] = 'The ' . $slug . ' spec floor had to be relaxed to build this: ' . $note;
        }

        // We state a memory speed in the spec sheet, so the build-time floor
        // must be able to prove it. Measured 2026-09-27: all 253 active 32GB+
        // rows do record a speed in specs.speed, and the floor correctly
        // rejects 5 legacy groups (2x DDR4-2133, DDR4-2666, DDR4-2933,
        // DDR4-3000). This check is the guard for the day a scrape lands
        // without one: the floor deliberately does not reject on missing
        // evidence (that emptied the RAM pool once already), so an unreadable
        // speed is reported here instead - we may build with it, we may not
        // publish it.
        $ram = $components['ram'] ?? null;
        if ($ram !== null && $this->memorySpeedToken($ram) === null) {
            $blockers[] = 'The memory speed for "' . $ram['name'] . '" is not recorded in the '
                . 'catalogue, so we cannot state it in a spec sheet or quote the build until it is verified.';
        }

        // The budget ceiling. A customer who says "about a thousand pounds" is
        // giving us a constraint, not a suggestion, and a build that overshoots
        // it by half is not a match for what they asked for - it is a quote they
        // will decline. The gate previously only ever checked price FRESHNESS,
        // so a build 48% over budget came back publishable: true purely because
        // its parts had been checked recently. Fresh and affordable are
        // different questions and both have to be asked.
        $budget = (float) ($result['budget'] ?? 0);
        $total = (float) ($result['total'] ?? 0);
        $tolerance = (float) config('pricing.budget_ceiling_tolerance', 0.05);
        $ceiling = $budget * (1 + $tolerance);
        $overBudget = $budget > 0 ? $total - $budget : 0.0;

        if ($budget > 0 && $total > $ceiling) {
            $blockers[] = sprintf(
                'This build comes to GBP %s, which is GBP %s (%.0f%%) over the GBP %s budget, so it cannot be published as a match for that budget. Either fit the ceiling or present it explicitly as a step up.',
                number_format($total, 2),
                number_format($overBudget, 2),
                $overBudget / $budget * 100,
                number_format($budget, 2)
            );
        }

        $result['qualityGate'] = [
            'publishable' => $blockers === [],
            'blockers' => $blockers,
            'freshness' => $freshness,
            'floorDegradations' => $degradations,
            'budget' => [
                'asked' => $budget,
                'total' => $total,
                'over' => $overBudget,
                'over_percent' => $budget > 0 ? round($overBudget / $budget * 100, 1) : 0.0,
                'tolerance' => $tolerance,
                'ceiling' => $ceiling,
                'within_ceiling' => $budget <= 0 || $total <= $ceiling,
            ],
        ];

        return $result;
    }

    /**
     * The memory speed token for a component, or null when the catalogue does
     * not record one.
     *
     * @param  array<string, mixed>|Component  $component
     */
    protected function memorySpeedToken($component): ?string
    {
        // THE COLUMN IS THE AUTHORITY. The name is NOT searched.
        //
        // Names are now disambiguated (see scripts/fix-ambiguous-names.php), so
        // a RAM kit reads:
        //   "Kingston FURY Beast 32 GB DDR5-5600 36 2 x 16GB"
        // and a part code embeds a second, DIFFERENT speed:
        //   "... 32 GB DDR5-6000 36 2 x 16GB SP032GXLWU60FFDL"
        // Searching the name first made the regex match whichever token came
        // earliest in a concatenated haystack, which is why the published
        // floor could be derived from a part number rather than the product.
        //
        // Rule 1: measure the data defect through the code path that USES it.
        $speed = null;
        $type = null;

        if ($component instanceof Component) {
            $speed = $component->memory_speed;
            $type = $component->memory_type;
            $specs = $component->specs ?? [];
        } else {
            $specs = $component['specs'] ?? [];
            $speed = $component['memory_speed'] ?? null;
            $type = $component['memory_type'] ?? null;

            if ($speed === null && isset($component['id'])) {
                $row = Component::find((int) $component['id']);
                $speed = $row?->memory_speed;
                $type ??= $row?->memory_type;
                $specs = $specs === [] ? ($row?->specs ?? []) : $specs;
            }
        }

        // Dedicated column first, then the specs it replaced.
        $candidates = [
            $speed,
            $specs['speed'] ?? null,
            $specs['memory_speed'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || trim((string) $candidate) === '') {
                continue;
            }

            if (preg_match('/(DDR\d)[-\s]?(\d{4})/i', (string) $candidate, $m) === 1) {
                return strtoupper($m[1]) . '-' . $m[2];
            }
        }

        // No speed anywhere: fall back to the generation alone if we know it,
        // which is still enough to decide DDR4 vs DDR5 for socket matching.
        $gen = trim((string) ($type ?? $specs['memory_type'] ?? $specs['type'] ?? ''));

        if (preg_match('/DDR\s?([345])/i', $gen, $m) === 1) {
            return 'DDR' . $m[1];
        }

        // Last resort, for rows predating the identity backfill.
        if (preg_match('/(DDR\d)[-\s]?(\d{4})/i', strtoupper((string) json_encode($specs)), $m) === 1) {
            return strtoupper($m[1]) . '-' . $m[2];
        }

        return null;
    }

    /**
     * Pick the absolute best (newest + highest performance) component per
     * category with no budget constraint â€” the "dream build" showcase.
     * Still enforces socket compatibility so the build is viable.
     */
    protected function pickIdealBuild($categories, ?string $purpose, ?string $resolution): array
    {
        $pools = $this->buildPools($categories, $purpose, $resolution);

        $selection = [];
        $spent = 0.0;
        $cpuSocket = null;

        // Socket-locked categories come after the CPU so they can be matched.
        $ordered = array_merge(
            array_values(array_diff(array_keys($pools), ['motherboard', 'cooler'])),
            ['motherboard', 'cooler']
        );

        foreach ($ordered as $slug) {
            $pool = $pools[$slug] ?? collect();

            if ($pool->isEmpty()) {
                continue;
            }

            // Case requirement: no sub-Â£50 case, and no shroud-less "crate"
            // cases (2026-09-27 boss directive â€” cheap low-quality case must
            // never ship). The case must cost at least Â£50 AND either declare
            // a PSU shroud in its specs or be a mainstream tower we know has
            // one (the cheap ITX/SFF cubes like SG13 / A3-mATX / Jonsbo C6 are
            // excluded by name). Applied to EVERY pick path incl. the dream
            // build and relax-to-cheapest fallbacks.
            if ($slug === 'case') {
                $pool = $pool->filter(fn (Component $component) => $this->acceptableCase($component));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // PSU floor (boss directive 2026-09-27): the dream build must also
            // respect the resolution + GPU minimum (750W/850W/1000W and the
            // GPU's own hardware requirement).
            if ($slug === 'psu') {
                $psuFloor = $this->minPsuFor($resolution, $selection['gpu'] ?? null);
                $pool = $pool->filter(fn (Component $component) => $this->psuWattage($component) >= $psuFloor);

                if ($pool->isEmpty()) {
                    continue;
                }
            }

            // The dream build carries a discrete graphics card too, so it is
            // held to the same two quality rules as a budgeted build: the
            // card has to be honest for the requested resolution, and the
            // processor has to be able to feed it. A "best possible 4K PC"
            // that pairs an Arc A380 with a 24-core processor is not the best
            // possible 4K PC.
            if ($slug === 'gpu') {
                $pool = $pool->filter(fn (Component $component) => $this->gpuMeetsResolution($component, $resolution));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            if ($slug === 'cpu') {
                $pool = $pool->filter(fn (Component $component) => $this->meetsDgpuCpuStandard($component));
                if ($pool->isEmpty()) {
                    continue;
                }
            }

            if ($this->socketLocked($slug)) {
                if ($cpuSocket === null) {
                    continue;
                }

                $socketFits = fn (Component $component) => $this->fitsSocket($slug, $component, $cpuSocket);
                $pick = $pool->filter($socketFits)->sortByDesc('score')->first();

                // No scored pick that fits â€” relax to the cheapest compatible
                // part so the dream build is still a buildable PC.
                if ($pick === null) {
                    $pick = $pool->filter($socketFits)->sortBy('price')->first();
                }
            } else {
                $pick = $pool->sortByDesc('score')->first()
                    ?? $pool->sortBy('price')->first();
            }

            if ($pick === null) {
                continue;
            }

            $selection[$slug] = [
                'id' => $pick->id,
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'score' => $pick->score,
                'chipset' => $pick->chipset ?? null,
                'wattage' => in_array($slug, ['cpu', 'gpu', 'psu'], true) ? $this->componentWattage($slug, $pick) : 0,
            ];

            if ($slug === 'cpu') {
                $cpuSocket = $pick->socket;
            }

            $spent += (float) $pick->price;
        }

        $selection = $this->ensurePowerAdequacy($selection, $pools, $resolution);
        $spent = (float) collect($selection)->sum('price');

        return $this->decorateBuild(
            [
                'components' => $selection,
                'total' => $this->pricing->completePrice($spent),
                'remaining' => 0.0,
            ],
            0,
            'ideal',
            apuWarning: ! isset($selection['gpu']),
            resolution: $resolution,
        );
    }

    /**
     * Score a component for the AI build. The score balances two goals:
     *
     * 1. Newest-tech preference â€” a component's generation tier (platform)
     *    contributes a base bonus so current hardware is strongly preferred.
     * 2. Best value within that platform â€” within the same generation tier the
     *    option with the best performance per pound wins, rather than the most
     *    expensive or highest-PERF part.
     *
     * @param  float  $maxPrice  highest price in the category, used to normalise
     *                           price so value is comparable across categories
     */
    protected function scoreComponent(Component $component, Category $category, ?string $purpose, ?string $resolution, float $maxPrice): int
    {
        $specs = $component->specs ?? [];

        $generation = in_array($category->slug, ['cpu', 'gpu'], true)
            ? $this->generationScore($component, $category)
            : 0;

        $performance = match ($category->slug) {
            'cpu' => $this->scoreCpu($specs, $purpose),
            'gpu' => $this->scoreGpu($specs, $purpose, $resolution),
            // Future-proofing (boss directive 2026-09-27): builds are designed
            // to last a couple of generations, so 32GB is the modern gaming
            // baseline and larger kits score higher than the old 64GB-or-nothing.
            'ram' => $this->scoreRam($component),
            // Storage tiers reward the capacity a modern library actually
            // needs; a 240GB drive is a fine boot disk but not a main drive.
            'storage' => $this->scoreStorage($component),
            'cooler' => $this->isAioCooler($component) ? 92 : 60,
            default => 60,
        };

        // Value: performance per unit of cost, relative to the category. The
        // curve is deliberately SHALLOW (0.5 + 0.5Â·norm) so the picker rewards
        // the best part that fits its budget share, not the absolute cheapest
        // that barely clears the bar â€” "best value to performance/quality with
        // future growth", never min/cheap hardware (boss directive 2026-09-27).
        $priceNorm = max(min((float) $component->price / max($maxPrice, 1.0), 1.0), 0.1);
        $value = $performance / (0.5 + (0.5 * $priceNorm));

        $score = $generation > 0
            ? (int) round($generation * $this->generationWeight($category) + min($value, 100) * (1 - $this->generationWeight($category)))
            : (int) round(min($value, 100));

        if ($category->slug === 'psu') {
            $score += $this->psuQualityBonus($component);
        }

        // AIO coolers are preferred for hand-built gaming rigs (2026-09-27
        // boss directive). The bonus is applied AFTER the value clamp so an
        // AIO still beats an equally-valued air tower when both fit the
        // budget â€” and the relax-to-cheapest fallback will pick an AIO when
        // one exists at all.
        if ($category->slug === 'cooler' && $this->isAioCooler($component)) {
            $score += 25;
        }

        // Premium-look case bonus (boss directive 2026-09-27): applied AFTER
        // the value clamp (same as the AIO bonus) so a showcase case (O11
        // Vision / H6 Flow class) beats a value case whenever the budget share
        // can include it â€” premium tiers get a guaranteed premium look, not
        // "whatever slack the CPU/GPU left over".
        if ($category->slug === 'case') {
            $score += $this->casePremiumBonus($component);
        }

        // Boss platform pyramid (2026-09-27): CPU and GPU picks are nudged
        // toward the platform/class band the Boss wants per resolution â€” AM4
        // Zen 3 + Intel 12th/13th on 1080p budgets, AM5 7000/8000/9000 or
        // Core Ultra 225-265K on 1440p, X3D (7800X3D / 9850X3D class) and the
        // 4K GPU bands on 4K; iGPU APUs for extreme budget. Applied AFTER
        // the clamps (same mechanic as the AIO/case bonuses) so they steer
        // within budget without overriding the value curve or honesty gates.
        if ($category->slug === 'cpu') {
            $score += $this->cpuPlatformBonus($component, $purpose, $resolution);
        }

        if ($category->slug === 'gpu') {
            $score += $this->gpuClassBonus($component, $purpose, $resolution);
        }

        return $score;
    }

    /**
     * Extra score for premium-look chassis: showcase / dual-chamber /
     * tempered-glass designs are worth the most (they ARE the premium look),
     * RGB lighting is a smaller premium, and a handful of trusted premium
     * brands add a little trust. Deliberately additive and modest â€” this is a
     * look bonus, not a quality judgement; acceptableCase still enforces the
     * Â£50 floor + PSU shroud on every pick path.
     */
    protected function casePremiumBonus(Component $component): int
    {
        $name = strtoupper((string) $component->name);
        $bonus = 0;

        // Showcase / dual-chamber / panoramic / wood-panel designs â€” the
        // "premium look" at the top of the range.
        if (preg_match('/\b(O11|VISION|PANORAMIC|DUAL|SHOWCASE|GLASS|H6|NORTH|TORRENT|MESHIFY|EVO|WOOD|PANEL)\b/', $name) === 1) {
            $bonus += 30;
        } elseif (preg_match('/\b(RGB|ARGB)\b/', $name) === 1) {
            // Lighting alone is still a visible premium over a plain tower.
            $bonus += 15;
        }

        // Trusted premium chassis brands.
        if (preg_match('/\b(LIAN LI|NZXT|FRACTAL|CORSAIR|BE QUIET|HYTE|HAVN|PHANTEKS)\b/', $name) === 1) {
            $bonus += 10;
        }

        return $bonus;
    }

    /**
     * Platform tier of a CPU for the Boss platform pyramid (2026-09-27):
     *
     * - `apu`      â€” AMD Ryzen/Athlon G-series APUs (5700G/8500G/8600G/8700G...)
     *                the extreme-budget iGPU lane (no dedicated GPU).
     * - `am4`      â€” AM4 socket (Ryzen 5000-series Zen 3 etc).
     * - `lga1700`  â€” Intel 12th/13th/14th gen.
     * - `am5`      â€” AM5 socket (Ryzen 7000/8000/9000).
     * - `lga1851`  â€” Intel Core Ultra (Arrow Lake).
     * - `other`    â€” anything else (never scores a pyramid bonus).
     */
    protected function cpuPlatformTier(Component $cpu): string
    {
        $name = strtoupper((string) $cpu->name);

        // AMD G-series APUs are the pyramid's iGPU lane. They are detected by
        // name first (some carry a wrong `socket` column, e.g. 5700G â†’ AM5).
        if (preg_match('/(?:RYZEN|ATHLON).*\b(?:[0-9]{3,4}G[TR]?|GE)\b/', $name) === 1) {
            return 'apu';
        }

        $socket = strtoupper((string) $this->canonicalSocket($cpu));

        return match (true) {
            str_contains($socket, 'AM5') => 'am5',
            str_contains($socket, 'AM4') => 'am4',
            str_contains($socket, '1851') => 'lga1851',
            str_contains($socket, '1700') => 'lga1700',
            default => 'other',
        };
    }

    /**
     * Resolution-aware CPU platform bonus (Boss platform pyramid 2026-09-27):
     *
     * - 4K / pro gaming        â†’ X3D (Ryzen 7 7800X3D / 9850X3D class) wins,
     *                            AM5 / Core Ultra ok, AM4/12-13th neutral.
     * - 1440p                  â†’ AM5 (7000/8000/9000) or Core Ultra 225-265K
     *                            preferred; X3D is a premium upgrade; AM4 /
     *                            12-13th only when the budget forces them.
     * - 1080p budget           â†’ AM4 Zen 3 + Intel 12th/13th i5/i7 preferred;
     *                            5000 X3D / high i9 / 7000-gen allowed at the
     *                            top of the band; AM5/Ultra are 1440p+
     *                            platforms and lose a few points here.
     *
     * Bonuses are modest on purpose: they steer within budget, they never
     * override the price/value curve or the hard honesty gates.
     */
    protected function cpuPlatformBonus(Component $cpu, ?string $purpose, ?string $resolution): int
    {
        $res = strtoupper((string) $resolution);
        if ($res === '') {
            return 0;
        }

        $is4k = str_contains($res, '4K') || str_contains($res, '2160');
        $is1440 = ! $is4k && (str_contains($res, '1440') || $res === '1440P');
        $gaming = $purpose === null
            || in_array(strtolower((string) $purpose), ['gaming', 'esports', 'streaming', 'gaming + streaming'], true);

        $tier = $this->cpuPlatformTier($cpu);
        $x3d = preg_match('/X3D/', strtoupper((string) ($cpu->chipset ?: $cpu->name))) === 1;
        $hay = strtoupper((string) ($cpu->chipset ?: $cpu->name));

        if ($is4k) {
            if ($gaming) {
                // 4K gaming is X3D territory (Ryzen 7 7800X3D / 9850X3D).
                // The bonus is large because the value curve rewards multi-core
                // non-X3D CPUs (e.g. 9900X) â€” +26 reliably beats them at 4K on
                // the post-clamp score while still losing to AM5 on 2K.
                return $x3d ? 26 : ($tier === 'am5' ? 6 : ($tier === 'lga1851' ? 4 : 0));
            }

            // 4K creation/AI favours multi-core AM5 / Core Ultra.
            return in_array($tier, ['am5', 'lga1851'], true) ? 8 : 0;
        }

        if ($is1440) {
            if ($gaming) {
                if ($x3d) {
                    return 10;
                }
                if (in_array($tier, ['am5', 'lga1851'], true)) {
                    return 10;
                }

                // AM4/12-13th are 1080p-band platforms â€” they only win at
                // 1440p when the budget genuinely cannot reach AM5/Ultra.
                return $tier === 'apu' ? -8 : 0;
            }

            return in_array($tier, ['am5', 'lga1851'], true) ? 8 : 0;
        }

        // 1080p (also the default resolution).
        if ($gaming) {
            if ($tier === 'apu') {
                return -10; // APUs are reserved for the no-GPU lane.
            }
            if ($x3d) {
                return 6; // 5000 X3D / 7000 X3D = higher-end 1080p tier.
            }
            if (preg_match('/CORE I9|RYZEN 9/', $hay) === 1) {
                return 4; // high-end i9 allowed at the top of the 1080p band.
            }
            if (in_array($tier, ['am4', 'lga1700'], true)) {
                return 5; // the pyramid's 1080p-budget target platforms.
            }

            return -8; // AM5 / LGA1851 are 1440p+ platforms on the pyramid.
        }

        return in_array($tier, ['am4', 'lga1700'], true) ? 4 : 0;
    }

    /**
     * Resolution-aware GPU class bonus (Boss GPU pyramid 2026-09-27):
     *
     * - 1080p basic     â†’ Arc B570 / RTX 3000-4000 / RX 7000 / RX 9060
     * - 1080p higher    â†’ Arc B580 / RX 9070 / RTX 5000
     * - 1440p entry     â†’ RTX 4070 / RTX 5060 Ti 16GB / Arc B580 / RX 5070 /
     *                     RX 7800 XT / RX 9070
     * - 1440p high      â†’ RX 9070 XT / RX 7900 / RTX 5070 / 5070 Ti / 5080
     * - 4K budget       â†’ RX 9070 XT / RX 7900 GRE / RTX 4080 / RTX 5070 Ti
     * - 4K high         â†’ RTX 4080-series / RTX 5080 / RX 7900 XTX
     *
     * Cards outside their band lose points so a 1080p-class card never sneaks
     * into a 1440p/4K pick and a 4K flagship never burns a 1080p budget.
     * Applied AFTER the VRAM honesty gate â€” hard rules (PSU floors, VRAM
     * resolution gates) are untouched.
     */
    protected function gpuClassBonus(Component $gpu, ?string $purpose, ?string $resolution): int
    {
        $res = strtoupper((string) $resolution);
        if ($res === '') {
            return 0;
        }

        $h = strtoupper((string) ($gpu->chipset ?: $gpu->name));

        $is4k = str_contains($res, '4K') || str_contains($res, '2160');
        $is1440 = ! $is4k && (str_contains($res, '1440') || $res === '1440P');

        if ($is4k) {
            if (preg_match('/\b(GEFORCE RTX 4080|GEFORCE RTX 5080|GEFORCE RTX 4090|GEFORCE RTX 5090|RX 7900 XTX|RX 7900 XT)\b/', $h) === 1) {
                return 12;
            }
            if (preg_match('/\b(RX 9070 XT|RX 9070 GRE|RX 7900 GRE|GEFORCE RTX 5070 TI)\b/', $h) === 1) {
                return 8;
            }

            // Below the 4K class â€” the VRAM gate already punishes these; this
            // keeps even a 16GB mid-range card out of a 4K recommendation.
            return -12;
        }

        if ($is1440) {
            if (preg_match('/\b(GEFORCE RTX 4070|GEFORCE RTX 5060 TI|ARC B580|RX 5070|RX 7800 XT|RX 9070)\b/', $h) === 1) {
                return 8;
            }
            if (preg_match('/\b(RX 9070 XT|RX 7900|GEFORCE RTX 5070 TI|GEFORCE RTX 5080|GEFORCE RTX 5070)\b/', $h) === 1) {
                return 6;
            }

            // 1080p-class card at 1440p â€” loses to every proper 1440p pick.
            return -8;
        }

        // 1080p. Overkill 1440p/4K cards are penalised first so they never
        // hoover a 1080p budget for zero visible gain.
        if (preg_match('/\b(GEFORCE RTX 507|GEFORCE RTX 508|GEFORCE RTX 509|GEFORCE RTX 408|GEFORCE RTX 409|RX 9070 XT|RX 7900)\b/', $h) === 1) {
            return -6;
        }
        if (preg_match('/\b(ARC B580|RX 9070|GEFORCE RTX 505|GEFORCE RTX 506)\b/', $h) === 1) {
            return 4;
        }
        if (preg_match('/\b(ARC B570|GEFORCE RTX 3|GEFORCE RTX 4|RX 7|RX 9060)\b/', $h) === 1) {
            return 6;
        }

        return 0;
    }

    /**
     * How much a component's architecture generation should dominate its
     * score. GPUs move fastest, so current-gen pushes harder there. CPUs are
     * longer-lived: a 12th/13th-gen Intel on LGA1700 or an AM4 Zen 3 Ryzen is
     * still a fully viable, upgradable platform (Boss directive 2026-09-27) â€”
     * the ladder still ranks them, just not high enough to crush value picks.
     */
    protected function generationWeight(Category $category): float
    {
        return $category->slug === 'gpu' ? 0.50 : 0.38;
    }

    /**
     * RAM scoring tuned for future-proof builds (boss directive 2026-09-27):
     * 32GB is the long-life gaming baseline, 48/64GB get the top tier, and
     * 16GB remains honest for an entry build rather than punished to zero.
     */
    protected function scoreRam(Component $component): int
    {
        $gb = (int) ($component->specs['capacity'] ?? 0);

        return match (true) {
            $gb >= 64 => 88,
            $gb >= 48 => 80,
            $gb >= 32 => 74,
            $gb >= 16 => 62,
            default => 50,
        };
    }

    /**
     * Storage scoring that stops rewarding only 2TB+ (boss directive
     * 2026-09-27): a 1TB Gen4 NVMe is the modern gaming baseline and scores
     * accordingly; 2TB+ (for the library-heavy gamer) tops the tree.
     */
    protected function scoreStorage(Component $component): int
    {
        $gb = $this->specCapacityGb($component);

        return match (true) {
            $gb >= 4000 => 90,
            $gb >= 2000 => 84,
            $gb >= 1000 => 76,
            $gb >= 500 => 66,
            default => 55,
        };
    }

    /**
     * PSU quality + headroom bonus (boss directive 2026-09-27): a quality,
     * correctly-sized PSU is the heart of a build that lasts generations.
     * Efficiency (80 Plus Gold/Platinum) and trusted brands get a modest edge,
     * and more headroom above the floor scores slightly better so the picker
     * never feels forced down to the cheapest compliant unit. The resolution
     * floor itself is enforced separately (psu pool filter + power adequacy).
     */
    protected function psuQualityBonus(Component $component): int
    {
        $name = strtoupper((string) $component->name);
        $bonus = 0;

        // Efficiency tier from the model name (catalogue stores no specs for
        // most PSUs, so the name is the honest signal we have).
        if (preg_match('/\b(?:TITANIUM|PLATINUM)\b/', $name) === 1) {
            $bonus += 18;
        } elseif (preg_match('/\bGOLD\b/', $name) === 1) {
            $bonus += 14;
        } elseif (preg_match('/\bBRONZE\b/', $name) === 1) {
            $bonus += 6;
        }

        // Trusted PSU brands whose units are known to be quiet, efficient and
        // long-lived. Explicit list, no hallucinated quality claims.
        $quality = [
            'BE QUIET', 'SEASONIC', 'CORSAIR RM', 'CORSAIR HX', 'CORSAIR AX',
            'NZXT C', 'MSI MAG A', 'GIGABYTE UD', 'GIGABYTE GP-P',
            'FRACTAL', 'SUPER FLOWER', 'ENHANCE', 'MONtech CENTURY',
            'THERMALTAKE TOUGHPOWER', 'COOLER MASTER MWE GOLD',
            'ASUS ROG', 'ASUS TUF', 'DEEPCOOL PL', 'VETROO',
        ];

        foreach ($quality as $marker) {
            if (str_contains($name, $marker)) {
                $bonus += 8;

                break;
            }
        }

        // Gentle headroom edge: a 750W floor unit that picks 850W or 1000W is
        // future-proof for a GPU upgrade; the bonus is capped so budget shares
        // still win (the value curve above keeps gross overspend in check).
        $wattage = $this->psuWattage($component);
        $bonus += $wattage >= 1200 ? 10 : ($wattage >= 1000 ? 8 : ($wattage >= 850 ? 6 : 0));

        return $bonus;
    }

    protected function scoreCpu(array $specs, ?string $purpose): int
    {
        $cores = (int) ($specs['cores'] ?? 8);

        return match ($purpose) {
            'streaming', 'creation' => min(100, 50 + ($cores - 8) * 8),
            'ai' => min(100, 55 + ($cores - 8) * 6),
            default => min(100, 50 + ($cores - 8) * 5),
        };
    }

    protected function scoreGpu(array $specs, ?string $purpose, ?string $resolution): int
    {
        $memory = (int) str_replace(['GB', 'G'], '', $specs['memory'] ?? '8');
        $res = strtoupper((string) $resolution);
        $is4k = str_contains($res, '4K') || str_contains($res, '2160');
        $is1440 = ! $is4k && (str_contains($res, '1440') || $res === '1440P');

        // VRAM baseline: 24GB+ flagship, 16GB strong, 12GB solid, 8GB entry,
        // less is legacy â€” with a hard honesty gate at 4K (an 8GB card cannot
        // honestly be sold as a 4K pick no matter how cheap it is).
        $base = 45;
        $base += $memory >= 24 ? 30 : ($memory >= 16 ? 20 : ($memory >= 12 ? 12 : ($memory >= 8 ? 4 : -8)));

        if ($is4k) {
            $base += $memory >= 12 ? 10 : -35;
        }

        if ($purpose === 'ai') {
            $base += 12;
        }

        return min(100, max(20, $base));
    }

    /**
     * Category slugs whose constituents must share the CPU socket with the
     * chosen CPU before they can be selected at all.
     */
    protected function socketLocked(string $slug): bool
    {
        return in_array($slug, ['motherboard', 'cooler'], true);
    }

    /**
     * Whether a component physically fits the CPU socket. Motherboards declare
     * the socket directly; coolers declare a supported_sockets spec list.
     * A cooler with no known socket list stays eligible (the compatibility
     * rule treats unknown sockets the same way).
     */
    protected function fitsSocket(string $slug, Component $component, ?string $cpuSocket): bool
    {
        if ($slug === 'motherboard') {
            return $component->socket === $cpuSocket;
        }

        if ($slug === 'cooler') {
            $supported = $component->specs['supported_sockets'] ?? null;

            if (empty($supported)) {
                return true;
            }

            return in_array($cpuSocket, $supported, true);
        }

        return true;
    }

    /**
     * The RAM generation a CPU socket implies (mainstream consumer sockets).
     * Returns null when the socket is unknown/unmapped so the pool is not
     * filtered (unknown sockets fall back to the existing compatibility rules).
     */
    protected function ramGenerationForSocket(?string $cpuSocket): ?string
    {
        $socket = strtoupper((string) $cpuSocket);

        return match (true) {
            $socket === '' => null,
            // AMD AM5, Intel Arrow Lake (LGA1851) â€” DDR5 only.
            str_contains($socket, 'AM5') || str_contains($socket, '1851') => 'DDR5',
            // AMD AM4 + Intel 12th/13th/14th gen (LGA1700) consumer ring â€” DDR4.
            str_contains($socket, 'AM4') || str_contains($socket, '1700')
                || str_contains($socket, '1151') || str_contains($socket, '1200')
                || str_contains($socket, '2066') => 'DDR4',
            default => null,
        };
    }

    /**
     * Whether a RAM component declares a current-generation memory type
     * (DDR4 or DDR5) anywhere in its name/specs. Used as the quality floor â€”
     * DDR3/DDR2 sticks are ancient and cannot be verified as compatible with
     * any modern platform, so they never get recommended.
     */
    protected function ramGenerationKnown(Component $ram): bool
    {
        return str_contains($this->ramGenerationHaystack($ram), 'DDR4')
            || str_contains($this->ramGenerationHaystack($ram), 'DDR5');
    }

    /**
     * Whether a RAM component's declared spec matches the CPU platform's
     * RAM generation. Generation is read from the `type` spec, the `speed`
     * spec (e.g. "DDR4-3200") or the catalogue name (e.g. "32GB DDR5 6000").
     * Any declared DDR-generation that differs from what the platform needs
     * is rejected (DDR3 vs DDR4 etc). A stick with no generation signal at
     * all stays eligible only if the quality floor has not already removed it
     * (better safe than picking an unverifiable part as the cheapest).
     */
    protected function ramGenerationMatches(Component $ram, string $generation): bool
    {
        $haystack = $this->ramGenerationHaystack($ram);

        if (str_contains($haystack, 'DDR4')) {
            return $generation === 'DDR4';
        }

        if (str_contains($haystack, 'DDR5')) {
            return $generation === 'DDR5';
        }

        if (str_contains($haystack, 'DDR')) {
            return false; // DDR3/DDR2/etc â€” never fits a modern platform.
        }

        return true;
    }

    /**
     * Combined name + spec text used for RAM generation detection.
     */
    protected function ramGenerationHaystack(Component $ram): string
    {
        $specs = $ram->specs ?? [];

        return strtoupper(implode(' ', [
            (string) ($specs['type'] ?? ''),
            (string) ($specs['speed'] ?? ''),
            (string) $ram->name,
        ]));
    }

    /**
     * How close a component is to the newest hardware generation (0-100).
     *
     * Explicit chipset maps give exact tiers for the parts that carry a
     * chipset; anything without one (or with an unknown family) falls back to
     * a generic board/architecture ladder. Scores are ordered newest-first so
     * the builder consciously drifts toward current-generation hardware.
     */
    protected function generationScore(Component $component, Category $category): int
    {
        if ($category->slug === 'cpu') {
            $tiers = [
                'Core Ultra 9 285K' => 100,
                'Core Ultra 7 265K' => 100,
                'Core Ultra 5 245K' => 100,
                'Ryzen 9 9950X' => 99,
                'Ryzen 9 9900X' => 98,
                'Ryzen 7 9800X3D' => 98,
                'Ryzen 7 9700X' => 95,
                'Ryzen 5 9600X' => 93,
                'Ryzen 7 7800X3D' => 90,
                'Ryzen 9 7950X' => 87,
                'Ryzen 9 7900X' => 86,
                'Ryzen 7 7700X' => 84,
                'Ryzen 7 7700' => 83,
                'Ryzen 5 7600X3D' => 85,
                'Ryzen 5 7600X' => 82,
                'Ryzen 5 7600' => 80,
                // AM4 Zen 3 remains a fully viable, upgradable platform
                // (Boss directive 2026-09-27): 5000-series drop straight into
                // existing AM4 boards, so these stay in the mid-tier rather
                // than being scored as obsolete junk.
                'Ryzen 7 5800X3D' => 82,
                'Ryzen 9 5900X' => 80,
                'Ryzen 7 5700X3D' => 79,
                'Ryzen 7 5800X' => 78,
                'Ryzen 7 5700X' => 77,
                'Ryzen 5 5600X' => 76,
                'Ryzen 5 5600' => 75,
                'Ryzen 5 5500' => 72,
                'Core i9-14900K' => 84,
                'Core i7-14700K' => 82,
                'Core i5-14600K' => 80,
                // Intel 12th/13th gen on LGA1700 = upgradable to 14th gen
                // (Boss directive 2026-09-27) â€” kept as solid mid-tier picks.
                'Core i9-13900K' => 80,
                'Core i7-13700K' => 78,
                'Core i5-13600K' => 76,
                'Core i9-12900K' => 76,
                'Core i7-12700K' => 74,
                'Core i5-12600K' => 72,
                'Core i5-12400F' => 68,
                'Core i5-12400' => 68,
                // X3D gaming flagships + APU iGPU lane (Boss platform pyramid
                // 2026-09-27): 9850X3D joins 9800X3D at the peak; G-series APUs
                // are ranked by iGPU power (8700G 780M > 8600G > 8500G > 5700G
                // Vega 8) so the extreme-budget no-GPU lane picks the best iGPU,
                // not just the cheapest.
                'Ryzen 7 9850X3D' => 97,
                'Ryzen 9 9950X3D' => 96,
                'Ryzen 9 9900X3D' => 95,
                'Ryzen 9 7900X3D' => 93,
                'Ryzen 9 7950X3D' => 92,
                'Ryzen 7 8700G' => 91,
                'Ryzen 5 8600G' => 88,
                'Ryzen 5 8500G' => 84,
                'Ryzen 7 5700G' => 79,
                'Ryzen 5 5600G' => 75,
                'Ryzen 5 5600GT' => 74,
                'Ryzen 5 5500GT' => 71,
                'Ryzen 5 3400G' => 64,
                'Ryzen 3 3200G' => 60,
            ];

            if ($component->chipset !== null && isset($tiers[$component->chipset])) {
                return $tiers[$component->chipset];
            }

            // Unknown CPU chipset â€” fall back to a rough architecture ladder.
            return $this->cpuLadder($component);
        }

        if ($category->slug === 'gpu') {
            return $this->gpuLadder($component);
        }

        return 60;
    }

    /**
     * Rough architecture ladder for CPU chipsets not in the explicit tier map.
     */
    protected function cpuLadder(Component $component): int
    {
        $h = strtoupper((string) ($component->chipset ?: $component->name));

        return match (true) {
            preg_match('/CORE ULTRA \d [25]/', $h) === 1 => 100,
            preg_match('/RYZEN \d 9(?:8|9|5)0/', $h) === 1 => 97,
            preg_match('/RYZEN \d 9[57]00/', $h) === 1 => 94,
            preg_match('/RYZEN \d 7[679]00/', $h) === 1 => 87,
            // X3D gaming flagships (Boss platform pyramid 2026-09-27): the
            // 9850X3D joins the 9800X3D at the peak. Explicit ladder rows so
            // the no-chipset DB rows (chipset NULL, name-only) still score
            // correctly instead of falling through to the generic RYZEN tier.
            preg_match('/9800X3D|9850X3D/', $h) === 1 => 97,
            preg_match('/7800X3D/', $h) === 1 => 90,
            preg_match('/7600X3D|7500X3D/', $h) === 1 => 85,
            preg_match('/5800X3D|5700X3D/', $h) === 1 => 80,
            // Extreme-budget iGPU lane (Boss platform pyramid 2026-09-27):
            // Ryzen G-series APUs ranked by iGPU power â€” 8700G (Radeon 780M),
            // 8600G, 8500G (740M), 5700G/5600G (Vega 8/7), down to the old
            // 3400G/3200G â€” so a no-GPU build picks the best iGPU available
            // in its budget, not simply the cheapest.
            preg_match('/RYZEN 7 8700G|RYZEN 5 8600G|RYZEN 5 8500G/', $h) === 1 => 87,
            preg_match('/RYZEN 7 5700G|RYZEN 5 5600G|RYZEN 5 5600GT|RYZEN 5 5500GT/', $h) === 1 => 76,
            preg_match('/RYZEN 5 3400G|RYZEN 3 3200G/', $h) === 1 => 64,
            // AM4 Zen 3 (5000-series) â€” still viable + upgradable per boss
            // directive 2026-09-27. Explicit ladder so it never scores as junk.
            preg_match('/RYZEN 7 5800X3D|RYZEN 9 5900X|RYZEN 7 57|RYZEN 5 56|RYZEN 5 55/', $h) === 1 => 80,
            preg_match('/CORE I9-14900K|CORE I7-14700K|CORE I5-14600K/', $h) === 1 => 82,
            // Intel 12th/13th gen on LGA1700 â€” upgradable to 14th gen,
            // still viable per boss directive 2026-09-27.
            preg_match('/CORE I9-13900K|CORE I7-13700K|CORE I5-13600K|CORE I9-12900K|CORE I7-12700K|CORE I5-12600K/', $h) === 1 => 76,
            preg_match('/CORE I5-12400|CORE I5-12400F/', $h) === 1 => 70,
            preg_match('/CORE ULTRA/', $h) === 1 => 100,
            preg_match('/RYZEN/', $h) === 1 => 85,
            preg_match('/CORE I/', $h) === 1 => 75,
            default => 60,
        };
    }

    /**
     * Architecture ladder for GPUs, keyed on chipset (or name when unknown).
     * Newest lineups rank highest so the builder gravitates to current tech.
     */
    protected function gpuLadder(Component $component): int
    {
        $h = strtoupper((string) ($component->chipset ?: $component->name));

        $ladder = [
            'RTX 5' => 100,
            'RX 9' => 97,
            'ARC B' => 92,
            'RTX 4' => 86,
            'RX 7' => 80,
            'RTX 3' => 72,
            'ARC A' => 66,
            'RX 6' => 60,
            'RTX 2' => 52,
        ];

        foreach ($ladder as $prefix => $score) {
            if (str_contains($h, $prefix)) {
                return $score;
            }
        }

        if (preg_match('/GTX 1[0-9]|RX 5/', $h) === 1) {
            return 40;
        }

        return 30;
    }

    /**
     * Whether a selection contains every category required for a working PC.
     *
     * @param  array<string, mixed>  $components
     */
    protected function isComplete(array $components, bool $allowMissingGpu = false): bool
    {
        $required = $this->requiredCategories;

        if ($allowMissingGpu) {
            $required = array_values(array_diff($required, ['gpu']));

            // Allowing the GPU to go missing is only safe if something can
            // actually drive the display. Without this guard a build could
            // "complete" on an F-series CPU with no integrated graphics and
            // no graphics card â€” a PC with no picture at all.
            if (! isset($components['gpu']) && ! $this->cpuCanDriveDisplay($components['cpu'] ?? null)) {
                return false;
            }
        }

        return array_diff($required, array_keys($components)) === [];
    }

    /**
     * Whether the picked CPU can output a picture on its own. Missing CPU or
     * unreadable component data is treated as "no", because a no-display
     * build must never be presented as complete.
     *
     * @param  mixed  $cpu  Component model, selection array, or null.
     */
    protected function cpuCanDriveDisplay($cpu): bool
    {
        if ($cpu === null) {
            return false;
        }

        $model = $cpu instanceof Component ? $cpu : Component::find(is_array($cpu) ? ($cpu['id'] ?? 0) : (int) $cpu);

        return $model instanceof Component && $this->hasIntegratedGraphics($model);
    }

    /**
     * Whether a CPU carries built-in graphics (an APU / iGPU) â€” the hardware
     * the no-dedicated-GPU path depends on. Prefers explicit spec data and
     * falls back to catalogue naming conventions (AMD G-suffix APUs, Intel
     * non-F/Core-Pentium-Celeron desktop parts with HD/UHD/Iris graphics).
     */
    protected function hasIntegratedGraphics(Component $component): bool
    {
        $specs = $component->specs ?? [];

        $explicit = $specs['integrated_graphics'] ?? $specs['graphics'] ?? null;
        if ($explicit !== null) {
            return ! in_array(strtolower((string) $explicit), ['', '0', 'false', 'no', 'none'], true);
        }

        $name = strtoupper((string) $component->name);

        // AMD APUs â€” Ryzen G-series and Athlon G/GE parts carry Vega graphics.
        if (preg_match('/(RYZEN|ATHLON).*\b(?:[\d]+[A-Z]*G)\b/', $name) === 1) {
            return true;
        }

        // Intel â€” F/KF suffix means no graphics; everything else desktop has HD/UHD/Iris.
        if (str_contains($name, 'INTEL') || str_contains($name, 'CORE I') || str_contains($name, 'PENTIUM') || str_contains($name, 'CELERON')) {
            // A standalone F / KF token (e.g. "Core i5 F").
            if (preg_match('/\bKF?\b/', $name) === 1) {
                return false;
            }

            // The suffix is part of the part number, not a separate word, so
            // the word-boundary check above cannot see it: 12100F, 12100KF,
            // 225F, 14400F all have the F welded to the digits. Test the last
            // token directly. Without this, an F part looks like it has
            // graphics and an APU-only build ends up with no display output.
            // Note the K is optional and the F is not ("K?F", never "KF?",
            // which would mean a mandatory K).
            $tokens = preg_split('/[\s\-]+/', $name);
            $lastToken = (string) end($tokens);
            if (preg_match('/^(?:[A-Z]{1,3})?\d{3,5}K?F$/', $lastToken) === 1) {
                return false;
            }

            // Legacy Pentium E-series desktop chips had no integrated graphics.
            if (preg_match('/PENTIUM E\d+/', $name) === 1) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Whether a selection relies on integrated graphics (no GPU part).
     *
     * @param  array<string, mixed>  $components
     */
    protected function hasApuComponents(array $components): bool
    {
        return ! isset($components['gpu']);
    }

    /**
     * Apply the quality floor per category so legacy/junk catalogue entries
     * never get recommended. Anything that cannot be verified against a
     * minimum sane spec is excluded (missing data is treated as sub-standard
     * for the parts where we know the minimum that modern systems require).
     *
     * @param  \Illuminate\Support\Collection<int, Component>  $pool
     * @return \Illuminate\Support\Collection<int, Component>
     */
    protected function viablePool(string $slug, $pool, ?string $cpuBrand = null)
    {
        return match ($slug) {
            'ram' => $pool->filter(fn (Component $component) =>
                $this->specCapacityGb($component) >= 16
                // No platform in the catalogue runs DDR3/DDR2. A stick that
                // declares a generation other than DDR4/DDR5 (or is ambiguous
                // because the name/specs are missing) cannot be verified as
                // current-gen, so it never ships in a complete build. This
                // kills ancient DDR3 "cheapest RAM" junk that previously made
                // physically impossible builds.
                && $this->ramGenerationKnown($component)
            ),
            'storage' => $pool->filter(fn (Component $component) => $this->specCapacityGb($component) >= 240),
            'psu' => $pool->filter(fn (Component $component) => $this->psuWattage($component) >= 400),
            'cpu' => $pool->filter(fn (Component $component) =>
                (int) ($component->specs['cores'] ?? 0) >= 4
                // A CPU with an unknown socket cannot be paired safely, so it
                // can never ship in a complete build. This kills legacy socket-
                // less junk (e.g. i5-2400) at the source for every pick path.
                && ! empty($this->canonicalSocket($component))
            ),
            // A graphics card needs at least 4GB of memory AND must be a
            // consumer gaming part â€” Radeon Pro / Quadro / Tesla workstation
            // cards are rejected outright, because they are not gaming cards
            // at any price and must never reach a customer build.
            'gpu' => $pool->filter(fn (Component $component) =>
                (int) ($component->specs['memory'] ?? 0) >= 4
                && $this->isConsumerGpu($component)),
            'motherboard' => $pool->filter(fn (Component $component) => ! empty($component->socket) || ! empty($component->chipset)),
            'cooler' => $cpuBrand === null || $cpuBrand === 'unknown'
                ? $pool
                : $pool->filter(fn (Component $component) => $this->coolerFitsBrand($component, $cpuBrand)),
            // Case requirement: never ship a sub-Â£50 cheap crate, and never
            // ship a shroud-less case (2026-09-27 boss directive, updated from
            // the earlier Â£25 floor â€” the Â£14.99 Aerocool CS-103 was already
            // banned; this now bans every sub-Â£50 case and the tiny ITX cubes
            // that lack a PSU shroud). `acceptableCase` enforces both the Â£50
            // price floor and a PSU-shroud rule in EVERY pick path including
            // the relax-to-cheapest fallbacks.
            'case' => $pool->filter(fn (Component $component) => $this->acceptableCase($component)),
            default => $pool,
        };
    }

    /**
     * Whether a processor is fit to sit behind a discrete graphics card.
     *
     * Applies the MIN_DGPU_CPU_CORES floor and rejects APUs, because once a
     * graphics card is fitted the processor's own graphics are dead weight
     * and cost the customer money for nothing. See the constant for the
     * measured evidence behind both halves of the rule.
     *
     * Core count is read from specs and falls back to nothing: a CPU whose
     * core count we cannot verify is REJECTED, because a floor must never pass
     * a part it cannot read. (This cannot empty the pool in practice â€” the
     * cheapest compliant part is GBP 59.99 against a GBP 1,250 dGPU entry.)
     */
    protected function meetsDgpuCpuStandard(Component $cpu): bool
    {
        $cores = (int) (($cpu->specs ?? [])['cores'] ?? 0);

        if ($cores < self::MIN_DGPU_CPU_CORES) {
            return false;
        }

        // An APU behind a discrete card: the integrated graphics do nothing,
        // and in this catalogue the G-suffixed part is always dearer than the
        // equivalent chip without it.
        return ! $this->hasIntegratedGraphics($cpu);
    }

    /**
     * Performance tier of a graphics card, 1 (1080p entry) to 5 (4K).
     *
     * Built from real UK-market positioning of the parts in this catalogue.
     * VRAM alone is not enough: an Intel Arc A580 has 8GB and performs like an
     * RTX 3050, while an RTX 3060 also has 12GB and is a genuine 1440p card.
     * So the chipset decides the tier, and the VRAM floor in
     * minGpuVramFor() is a second, independent gate.
     *
     * 0 is returned for an unrecognised card, which means "no opinion" â€” the
     * VRAM gate still applies but we never reject a card we simply have not
     * classified. Failing open on an unknown card is the right direction here:
     * wrongly banning a good card costs a sale, wrongly allowing a bad one
     * costs a review, and the two lists below are explicit precisely so a
     * reviewer can see exactly which cards are being trusted.
     */
    protected function gpuPerformanceTier(Component $gpu): int
    {
        // (2026-09-28): read specs.chipset as well as the chipset COLUMN.
        // The column is null on every GPU row in production - the scraper left
        // it empty - but the real model is in specs.chipset ("Arc A310",
        // "GeForce RTX 3050 6GB"). So the tier was previously being decided
        // against a blank string plus a title that had the model stripped out
        // ("Asus DUAL OC"), which is why an Arc A310 came back tier 0 "no
        // opinion" and failed open into the 1080p band. One card, one fix.
        $specs = (array) ($gpu->specs ?? []);

        $h = strtoupper(trim(
            (string) ($gpu->chipset ?? '')
            .' '.(string) ($specs['chipset'] ?? '')
            .' '.(string) ($specs['gpu_chipset'] ?? '')
            .' '.(string) $gpu->name
        ));

        // --- Tier 5: proper 4K (RTX 5070 Ti / RX 9070 class and above) ---
        foreach ([
            '/RTX 5090/', '/RTX 5080/', '/RTX 4090/', '/RTX 4080/',
            '/RTX 5070\s*TI/', '/RX 9070 XT/', '/RX 9070 GRE/', '/RX 9070\b/',
            '/RX 7900 XTX/', '/RX 7900 XT\b/', '/RX 6950 XT/',
            // NOTE: RX 6800 XT was previously listed here. It is a 16GB
            // 3070 Ti-class card, not a 4K flagship: sitting it alongside the
            // RTX 5090 / 4090 / 7900 XTX also made every tier-4 and tier-3 gate
            // silently satisfiable by a mid-range card. Verified against the
            // measured top-150 demand (Warhammer 40K: Space Marine 2 asks for
            // exactly "RX 6800 XT / RTX 3070"), so it belongs in tier 3.
        ] as $t5) {
            if (preg_match($t5, $h) === 1) {
                return 5;
            }
        }

        // --- Tier 4: strong 1440p, entry 4K (RTX 5070 / RX 7700 XT class) ---
        foreach ([
            '/RTX 5070\b/', '/RX 7800 XT/', '/RX 7700 XT/',
            // The RTX 4070 family also belongs here. It previously matched the
            // TIER 3 pattern '/RTX 4070/', which has no word boundary, so the Ti
            // and Ti Super variants fell into a 1440p bucket they exceed - and
            // disagreed with the separate score table in this same class, which
            // rates RTX 4070 TI SUPER at 750.
            '/RTX 4070\s*TI\s*SUPER/', '/RTX 4070\s*TI/', '/RTX 4070\b/',
        ] as $t4) {
            if (preg_match($t4, $h) === 1) {
                return 4;
            }
        }

        // --- Tier 3: real 1440p (RTX 5060 Ti / RX 6600 XT class) -----------
        foreach ([
            '/RTX 5060\s*TI/', '/RTX 4060\s*TI/',
            '/RX 9060 XT/', '/RX 6600 XT/', '/RX 6700 XT/',
            '/RX 6800 XT/', '/RX 6800\b/',
            // Keep the Ti explicit: tier 2 matches '/RTX 3070\b/', and a word
            // boundary exists at the space in "RTX 3070 Ti", so without this the
            // Ti would silently drop from tier 3 to tier 2.
            '/RTX 3070\s*TI/',
            '/RTX 3060\s*TI/', '/RTX 2080\s*TI/', '/RTX 2080\b/',
        ] as $t3) {
            if (preg_match($t3, $h) === 1) {
                return 3;
            }
        }

        // --- Tier 2: 1080p high / 1440p entry (RTX 4060 / RX 7600 class) ---
        foreach ([
            '/RTX 5060\b/', '/RTX 4060\b/', '/RX 7600 XT/', '/RX 7600\b/',
            '/RTX 3070\b/', '/RTX 3060\b/', '/ARC A750/', '/ARC B580/',
            '/ARC B570/', '/ARC A580/', '/GTX 1080\s*TI/',
        ] as $t2) {
            if (preg_match($t2, $h) === 1) {
                return 2;
            }
        }

        // --- Tier 1: 1080p entry (RTX 3050 / Arc A380 / RX 6500 XT class) ---
        foreach ([
            '/RTX 5050/', '/RTX 3050/', '/RX 6500 XT/', '/ARC A380/', '/ARC A310/',
            '/GTX 1660\s*SUPER/', '/GTX 1660\s*TI/', '/GTX 1660\b/', '/GTX 1070/',
        ] as $t1) {
            if (preg_match($t1, $h) === 1) {
                return 1;
            }
        }

        // --- Tail: the last cards left unclassified (2026-09-28) ---
        // Every remaining unclassified row in the live catalogue, named
        // explicitly. "Unclassified" is NOT a safe resting place: tier 0 means
        // "no opinion" and the band gate fails OPEN on it, so an unlisted card
        // quietly inherits whatever the customer was promised. A deliberately
        // modest tier is always safer than silence.
        foreach (['/RTX 2060\b/', '/RX 6600\b/'] as $t2b) {
            if (preg_match($t2b, $h) === 1) {
                return 2;
            }
        }

        // A workstation card, not a gaming one. Tier 1 keeps it out of the 1080p
        // and above bands - it belongs on a professional build and must not be
        // allowed to satisfy a gaming promise.
        if (preg_match('/RADEON PRO\b|WX 5100/', $h) === 1) {
            return 1;
        }

        return 0;
    }

    /**
     * Minimum performance tier a graphics card must reach for a resolution.
     *
     * 1080p has no floor: any card in the catalogue is a 1080p card, and
     * refusing one there would only push an entry customer onto a dearer
     * build. 1440p needs tier 2 (RTX 4060 / RX 7600 class) and 4K needs
     * tier 4 (RTX 5070 / RX 7700 XT class).
     */
    protected function minGpuTierFor(?string $resolution): int
    {
        // The tier floor is no longer a guess about what "1440p" means - it
        // is read straight off the published band table so the engine, the
        // storefront anchors and the marketing copy cannot drift apart.
        $band = $this->bandFor($resolution);

        return $band === null ? 0 : $band['floor_gpu_tier'];
    }

    /** Public alias so measurement scripts can read the floor. */
    public function minGpuTierForPublic(?string $resolution): int
    {
        return $this->minGpuTierFor($resolution);
    }

    /** Public tier test for probes - true when the card clears the band floor. */
    public function gpuTierMeetsResolution(Component $gpu, ?string $resolution): bool
    {
        $floor = $this->minGpuTierFor($resolution);
        $tier = $this->gpuPerformanceTier($gpu);

        return $floor === 0 || $tier === 0 || $tier >= $floor;
    }

    /**
     * Minimum VRAM a graphics card must carry for a resolution.
     *
     * A second, independent gate alongside the tier check â€” a card can carry
     * plenty of memory and still be a 1080p part (Arc A580 8GB, RTX 3050
     * 8GB), so the two rules together are what actually stop a 6GB Arc A380
     * being sold as a 4K machine.
     */
    /**
     * VRAM in GB for a graphics card.
     *
     * The catalogue stores this in `specs['memory']` (see scoreGpu and
     * PartDimensions), NOT `specs['vram_gb']`. Reading the wrong key silently
     * yields 0, which reads as "this card has no memory" and empties the pool
     * instead of raising an error - which is how the first entry-price
     * measurement reported "no complete 1440p build" against a catalogue that
     * had plenty of 8GB cards in it. One helper, one key.
     */
    public function gpuVramGb(Component $gpu): int
    {
        $raw = $gpu->specs['memory'] ?? null;

        if ($raw === null) {
            return 0;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $raw);
    }

    protected function minGpuVramFor(?string $resolution): int
    {
        $res = strtoupper((string) $resolution);

        return match (true) {
            str_contains($res, '4K'), str_contains($res, '2160') => 12,
            str_contains($res, '1440') => 8,
            default => 0,
        };
    }

    /**
     * Whether a graphics card is a CONSUMER gaming card at all.
     *
     * Found 2026-09-28, immediately after the resolution rule went in. The
     * Arc A380 was correctly rejected as a 1440p card, and the picker moved to
     * the next cheapest 8GB row â€” which was an AMD Radeon Pro WX 5100. That
     * is a WORKSTATION card, not a gaming card: no gaming display outputs, no
     * gaming drivers worth the name, and a price that reflects a completely
     * different market. Quoting one as a 1440p gaming PC would be an
     * indefensible claim, and it only got that far because an unrecognised
     * card is treated as "no opinion" by the tier rule.
     *
     * Professional parts are therefore banned outright from every build, at
     * every resolution, rather than merely being unclassified. The list covers
     * the families that actually turn up in PC-parts catalogues: AMD Radeon
     * Pro (WX / W / R / Studio), NVIDIA Quadro / RTX A-series / Tesla, and
     * the older NVS / GRID / FirePro lines. A build may not contain one.
     */
    protected function isConsumerGpu(Component $gpu): bool
    {
        $h = strtoupper(trim(
            (string) ($gpu->chipset ?? '') . ' ' . (string) $gpu->name
        ));

        foreach ([
            '/RADEON\s*PRO/', '/QUADRO/', '/RTX\s*A\d/', '/TESLA/',
            '/FIREPRO/', '/\bNVS\b/', '/GRID\s*[A-Z]?\d/', '/INSTINEC/',
            '/RADEON\s*R\s*\d{3}/',
        ] as $pro) {
            if (preg_match($pro, $h) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a graphics card is honest to sell for a resolution.
     *
     * This is the single definition of the rule, used by the picker, the
     * dream build and the publish gate alike, so a build can never be
     * generated at one resolution and published at another.
     *
     * @param  array<string, mixed>|null  $gpu  selection entry, or a Component
     */
    public function gpuMeetsResolution($gpu, ?string $resolution): bool
    {
        $tierFloor = $this->minGpuTierFor($resolution);
        $vramFloor = $this->minGpuVramFor($resolution);

        $model = $gpu instanceof Component
            ? $gpu
            : Component::find(is_array($gpu) ? ($gpu['id'] ?? 0) : (int) $gpu);

        if (! $model instanceof Component) {
            // An unreadable card cannot be verified against the floor, so it
            // fails â€” the same fail-closed rule the CPU core-count floor uses.
            return false;
        }

        // Professional cards are out at every resolution, before any tier or
        // memory question is asked.
        if (! $this->isConsumerGpu($model)) {
            return false;
        }

        if ($tierFloor === 0 && $vramFloor === 0) {
            return true;
        }

        if ((int) (($model->specs ?? [])['memory'] ?? 0) < $vramFloor) {
            return false;
        }

        $tier = $this->gpuPerformanceTier($model);

        return $tier === 0 || $tier >= $tierFloor;
    }

    /**
     * Whether a cooler is an all-in-one (AIO) liquid cooler, detected from
     * the catalogue name. AIOs ship with everything included (pump, radiator,
     * fans) and are required on every PCTG build.
     *
     * False positives matter more here than false negatives. This list used to
     * contain 'AQUA ELITE', which is Thermalright's *air tower* line, so the
     * engine was scoring a Thermalright Aqua Elite V3 as liquid cooling and
     * every generated build was quietly mislabelled "AIO" in the spec sheet.
     * Shipping an air cooler described as water cooling is a warranty
     * complaint and a 5-star review risk, so the marker list now contains only
     * unambiguous liquid-cooler product lines, and explicitly-labelled air
     * coolers are rejected.
     */
    protected function isAioCooler(Component $cooler): bool
    {
        $name = strtoupper((string) $cooler->name);

        // Unambiguous liquid-cooler product lines.
        $markers = [
            'LIQUID FREEZER', 'AIO', 'NAUTILUS', 'KRAKEN', 'MASTERLIQUID',
            'CORELIQUID', 'FROSTFLOW', 'GALAHAD', 'MYSTIQUE', 'PURE LOOP',
            'LE240', 'LE360', 'CG530', 'AORUS WATERFORCE', 'NUCLEUS AIO',
            'GLACIER ONE', 'HYDROSHIFT', 'H100I', 'H115I', 'H150I',
            'SILENT LOOP', 'LIGHT LOOP', 'TH240', 'TH360', 'ATMOS',
            'WATERFORCE', 'WATER CHILLER', 'RYU', 'VANTAGE', 'SPEARHEAD',
        ];

        foreach ($markers as $marker) {
            if (str_contains($name, $marker)) {
                return true;
            }
        }

        // Explicitly-labelled air coolers are not liquid, even when the model
        // name contains a word that appears in some AIO line ("Freezer 8A",
        // "Aqua Elite", "Freezer 36" without the LIQUID prefix).
        foreach (['AIR COOLER', 'AIRCOOLER', 'TOWER', 'HEATSINK', 'HEAT SINK', 'FREEZER 8', 'FREEZER 12', 'FREEZER 36', 'FREEZER 7', 'FREEZER 9'] as $air) {
            if (str_contains($name, $air)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Case quality rule (2026-09-27 boss directive): a case is acceptable only
     * when it costs at least Â£50 AND has a PSU shroud. Shroud detection:
     * 1. Explicit specs.psu_shroud === true wins outright.
     * 2. Otherwise the case must be a known mainstream tower brand/model that
     *    ships PSU shrouds as standard (whitelisted by name â€” verified against
     *    manufacturer specs; NOT a sweeping "every Â£50 case" assumption).
     * Anything not provably suitable is rejected (better to skip than ship a
     * shroud-less crate â€” build paths then relax to the cheapest ACCEPTABLE
     * case, never below this floor).
     */
    protected function acceptableCase(Component $component): bool
    {
        if ((float) $component->price < 50.0) {
            return false;
        }

        $specs = $component->specs ?? [];
        $explicit = $specs['psu_shroud'] ?? null;
        if ($explicit !== null) {
            return filter_var($explicit, FILTER_VALIDATE_BOOLEAN);
        }

        $name = strtoupper((string) $component->name);

        // Whitelist of mainstream tower families whose mid-tower models carry
        // a PSU shroud as standard (no SFF cubes â€” those are explicitly NOT
        // here even when they cost Â£50+, e.g. Silverstone SG13, Lian Li A3-
        // mATX, Jonsbo C6/D31, Cooler Master NR200P, Thermaltake Tower 100-
        // 600, HYTE Y40/Y60/Y70 dual-chamber, MSI PANO, Lian Li O11 Mini).
        $shroudTowerMarkers = [
            // NZXT mainline flow towers.
            'NZXT H5 FLOW', 'NZXT H6 FLOW', 'NZXT H7 FLOW', 'NZXT H9 FLOW',
            // Corsair mainstream.
            'CORSAIR 3000D', 'CORSAIR 3500X', 'CORSAIR 4000D', 'CORSAIR 4500X',
            'CORSAIR 5000D', 'CORSAIR 2500X', 'CORSAIR 2800X', 'CORSAIR 3200D',
            'CORSAIR FRAME 4000D', 'CORSAIR FRAME 4500X', 'CORSAIR FRAME 5000D',
            'CORSAIR AIR 5400',
            // Fractal mainstream.
            'FRACTAL FOCUS 2', 'FRACTAL POP', 'FRACTAL MESHIFY 2', 'FRACTAL MESHIFY 3',
            'FRACTAL NORTH', 'FRACTAL DEFINE 7', 'FRACTAL TORRENT', 'FRACTAL ERA 2',
            // Lian Li mainstream towers (NOT the A-mATX/C6 cube lines).
            'LIAN LI LANCOOL 207', 'LIAN LI LANCOOL 205', 'LIAN LI LANCOOL 216',
            'LIAN LI LANCOOL 217', 'LIAN LI LANCOOL III', 'LIAN LI O11 VISION',
            'LIAN LI O11D EVO', 'LIAN LI O11 DYNAMIC EVO', 'LIAN LI O11 AIR',
            'LIAN LI LANCOOL 215', 'LIAN LI VECTOR V100', 'LIAN LI VECTOR V150',
            // Phanteks mainstream.
            'PHANTEKS XT', 'PHANTEKS ECLIPSE G', 'PHANTEKS NV5', 'PHANTEKS NV7',
            'PHANTEKS EVOLV X2', 'PHANTEKS ENTHOO PRO',
            // Antec mainstream airflow.
            'ANTEC FLUX', 'ANTEC C5', 'ANTEC C8', 'ANTEC P20C', 'ANTEC P30',
            'ANTEC PERFORMANCE 1 M', 'ANTEC AX61', 'ANTEC AX90',
            // Montech mainstream airflow.
            'MONTECH AIR 903', 'MONTECH AIR 100', 'MONTECH XR', 'MONTECH SKY TWO',
            'MONTECH TEN', 'MONTECH KING 95', 'MONTECH AIR 905',
            // Deepcool mainstream.
            'DEEPCOOL CG530', 'DEEPCOOL CH370', 'DEEPCOOL CH270', 'DEEPCOOL CH260',
            'DEEPCOOL CG540', 'DEEPCOOL MATREXX 40', 'DEEPCOOL MACUBE 110',
            'DEEPCOOL MATREXX 50', 'DEEPCOOL CH510', 'DEEPCOOL CC360',
            // MSI MAG FORGE mainstream (NOT PANO â€” dual-chamber, no full shroud).
            'MSI MAG FORGE 112R', 'MSI MAG FORGE 120A', 'MSI MAG FORGE 320R',
            'MSI MAG FORGE 321R', 'MSI MAG FORGE M100A', 'MSI MAG FORGE 110R',
            'MSI MAG FORGE 100A', 'MSI MAG FORGE 100R',
            // be quiet! mainstream.
            'BE QUIET! PURE BASE 501', 'BE QUIET! PURE BASE 600', 'BE QUIET! PURE BASE 802',
            'BE QUIET! LIGHT BASE 500', 'BE QUIET! LIGHT BASE 600', 'BE QUIET! LIGHT BASE 900',
            'BE QUIET! DARK BASE PRO 901', 'BE QUIET! SILENT BASE 802',
            // Cooler Master mainstream.
            'COOLER MASTER MASTERBOX TD500', 'COOLER MASTER QUBE 540',
            'COOLER MASTER MASTERBOX 520', 'COOLER MASTER COSMOS',
            // Thermaltake mainstream towers (NOT the Tower 100-600 columns).
            'THERMALTAKE VIEW 270', 'THERMALTAKE VIEW 380', 'THERMALTAKE VIEW 170',
            'THERMALTAKE VERSATILE', 'THERMALTAKE REVOB',
            // Value mainstream brands.
            'ZALMAN S2', 'ZALMAN T3', 'NOX INFINITY', 'COUGAR MX110', 'COUGAR CFV235',
            'ENDORFY VENTUM 200', 'ENDORFY VENTUM 210', 'GAMDIAS', 'DARKFLASH',
            'MUSETEX NN8', 'OKINOS', 'BGEARS', 'DIYPC', 'SAMA SV01',
            // Asus mainstream.
            'ASUS PRIME AP201', 'ASUS PRIME AP202', 'ASUS PRIME AP303',
            'ASUS TUF GAMING GT502', 'ASUS TUF GAMING GT302', 'ASUS PROART PA401',
            'ASUS PROART PA602', 'ASUS ROG STRIX HELIOS',
            // Silverstone mainstream (NOT Sugo/SG cubes).
            'SILVERSTONE FLP01', 'SILVERSTONE FLP02', 'SILVERSTONE RM52',
            // Jonsbo mainstream towers only (NOT C6/D31/N-series cubes).
            'JONSBO NV10', 'JONSBO V12', 'JONSBO TK-1',
            // Fractal special case: Pop/Meshify variants above include shroud
            // by family; HAVN dual-case; APNX open frame EXCLUDED (no shroud).
            'HAVN HS420', 'HAVN BF 360',
        ];

        foreach ($shroudTowerMarkers as $marker) {
            if (str_contains($name, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capacity in GB from a spec value like "32GB", "1024GB" or "1TB".
     */
    protected function specCapacityGb(Component $component): int
    {
        $raw = strtoupper((string) ($component->specs['capacity'] ?? '0'));

        if (str_contains($raw, 'TB')) {
            return (int) ((float) str_replace('TB', '', $raw) * 1000);
        }

        if (str_contains($raw, 'GB')) {
            return (int) str_replace('GB', '', $raw);
        }

        return (int) $raw;
    }

    /**
     * Total RAM capacity in GB, tolerating the messy ways the catalogue
     * records it.
     *
     * `specs['capacity']` is the reliable field, but plenty of scraped rows
     * only carry capacity in the product name ("32GB DDR5 6000", "PNY
     * Performance 16 GB"), and some name a multi-stick kit as "(2x16GB)".
     * Anything unverifiable returns 0, which the spec floor then rejects â€” a
     * memory module whose capacity we cannot read is not something we can
     * honestly promise a customer.
     */
    protected function ramCapacityGb(Component $component): int
    {
        $fromSpecs = $this->specCapacityGb($component);
        if ($fromSpecs > 0) {
            return $fromSpecs;
        }

        $name = strtoupper((string) $component->name);

        // Multi-stick kit first: "32GB (2X16GB)" / "2X16GB".
        if (preg_match('/(\d+)\s?X\s?(\d+)\s?GB/i', $name, $m) === 1) {
            return (int) $m[1] * (int) $m[2];
        }

        // Otherwise the largest single capacity token in the name.
        if (preg_match_all('/(\d+)\s?(GB|TB)\b/i', $name, $all, PREG_SET_ORDER) > 0) {
            $best = 0;

            foreach ($all as $set) {
                $value = (int) $set[1] * (strtoupper($set[2]) === 'TB' ? 1000 : 1);
                $best = max($best, $value);
            }

            return $best;
        }

        return 0;
    }

    /**
     * Reject memory that cannot run a modern game: DDR3/PC3, and DDR4 quoted
     * below 3200 MT/s. DDR5 and DDR4-3200+ pass.
     *
     * The asymmetry here is deliberate and important. Most kits in this
     * catalogue are named by series and capacity only â€” "G.Skill Ripjaws V
     * 32 GB" â€” with the DDR generation recorded in an absent or empty
     * `specs.type` field. An earlier version returned false whenever the
     * generation could not be read, which failed the legitimate 32GB baseline
     * kit, emptied the RAM pool, and then tripped the empty-pool fallback that
     * silently un-floored the whole category. A floor must reject on *positive
     * evidence* of non-compliance, never on missing evidence, so an
     * unstated-but-modern generation passes and the capacity check still does
     * the real work (4GB and 16GB kits never get past it regardless).
     */
    protected function isModernMemory(Component $component): bool
    {
        $specs = $component->specs ?? [];

        $hay = strtoupper(trim(
            (string) ($specs['type'] ?? '')
            . ' ' . (string) ($specs['memory_type'] ?? '')
            . ' ' . (string) ($specs['technology'] ?? '')
            // This catalogue records the memory generation and speed in
            // `specs.speed` as a combined token ("DDR4-2133", "DDR5-6000"),
            // not in `specs.type`. The method used to read only type /
            // memory_type / technology, so the token that actually carries the
            // speed was never examined: 251 of 253 active 32GB+ rows state no
            // speed in their name, which left the modern-memory floor judging
            // 2 rows and passing a DDR4-2133 kit as our 32GB standard. Read
            // every field that can carry it, plus the raw name.
            . ' ' . (string) ($specs['speed'] ?? '')
            . ' ' . (string) ($specs['memory_speed'] ?? '')
            . ' ' . (string) ($specs['clock_speed'] ?? '')
            . ' ' . (string) ($specs['frequency'] ?? '')
            . ' ' . (string) $component->name
        ));

        // --- Positive evidence of legacy: reject -------------------------
        if (str_contains($hay, 'PC3') || str_contains($hay, 'PC2') || str_contains($hay, 'DDR3')) {
            return false;
        }

        if (str_contains($hay, 'DDR4') || str_contains($hay, 'PC4')) {
            // Any explicitly quoted DDR4 speed must be at least 3200.
            if (preg_match('/DDR4[-\s]?(\d{4})/i', $hay, $m) === 1) {
                return (int) $m[1] >= 3200;
            }

            return true;
        }

        if (str_contains($hay, 'DDR5')) {
            return true;
        }

        // Generation unstated, but a speed IS stated. Anything under 3200 is
        // positive evidence of a legacy kit: DDR5 starts at 4800, so a stated
        // 2133/2400/2666 can only be DDR4-2133 or slower. This is the case
        // that catches a speed recorded without its generation token.
        if (preg_match('/\b(2133|2400|2666|3000)\b/', $hay) === 1) {
            return false;
        }

        // --- Generation simply not stated: not evidence of a problem -----
        return true;
    }

    /**
     * Hard PCTG spec floor (boss directive 2026-09-27: "just all build need to
     * meet the PCTG standard").
     *
     * Scoring can only *reward* a good part â€” it can never stop a 16GB kit or
     * a 120GB SATA drive from winning a slot on price, which is exactly how
     * the engine produced sub-standard builds. These are the PCTG minimums and
     * they are enforced as pass/fail, not as a bonus:
     *
     * - RAM must be 32GB+ of DDR4-3200 or DDR5 (the long-life gaming
     *   baseline). Sub-32GB and un-readable kits never reach a build.
     * - Storage must be a solid-state drive of 500GB+.
     *
     * Cooling is deliberately NOT decided here. The boss directive (2026-09-27)
     * is conditional: a dedicated-GPU build gets an all-in-one liquid cooler,
     * while an APU/iGPU-only build gets an air cooler, because the APU does not
     * need liquid cooling and forcing one would just add cost the customer
     * gains nothing from. Which one applies is only knowable once we know
     * whether a discrete GPU was picked, so the rule is applied per pick
     * branch in `pickBudgetBuild()` (see `aioCoolers()` / `airCoolers()`).
     *
     * The APU path stays valid: it only removes the discrete GPU requirement,
     * never the memory or storage floor.
     */
    protected function passesSpecFloor(Component $component, Category $category): bool
    {
        return match ($category->slug) {
            'ram' => $this->ramCapacityGb($component) >= self::MIN_RAM_GB
                && $this->isModernMemory($component),
            'storage' => $this->specCapacityGb($component) >= self::MIN_STORAGE_GB
                && ! $this->isHardDrive($component),
            default => true,
        };
    }

    /**
     * Cooler pool for a dedicated-GPU build: liquid only. A discrete GPU is a
     * hot component, so the PCTG standard is a proper AIO, and shipping an air
     * tower described as water cooling would be a misdescription.
     *
     * @return \Illuminate\Support\Collection<int, Component>
     */
    protected function aioCoolers($pool)
    {
        return $pool->filter(fn (Component $cooler) => $this->isAioCooler($cooler))->values();
    }

    /**
     * Cooler pool for an APU / integrated-graphics-only build: air only. The
     * APU's cooler is adequate, and an air cooler is cheaper and simpler â€”
     * which is exactly what a budget customer wants. No radiator, no pump, no
     * leak risk, and the money goes into the GPU-less performance instead.
     *
     * @return \Illuminate\Support\Collection<int, Component>
     */
    protected function airCoolers($pool)
    {
        return $pool->reject(fn (Component $cooler) => $this->isAioCooler($cooler))->values();
    }

    /**
     * Pools for a build that will carry a discrete GPU: liquid cooling.
     *
     * Extracted so the cooling rule has one definition. If the liquid pool is
     * somehow empty we fall back to the unfiltered cooler pool rather than
     * failing to produce any build at all - an entry build is better than no
     * build, and the gate records what happened.
     *
     * @param  array<string, mixed>  $pools
     * @return array<string, mixed>
     */
    protected function dedicatedGpuPools(array $pools): array
    {
        $coolerPool = $pools['cooler'] ?? collect();

        $withGpu = $pools;
        $withGpu['cooler'] = $this->aioCoolers($coolerPool);

        if ($withGpu['cooler']->isEmpty()) {
            $withGpu['cooler'] = $coolerPool;
        }

        return $withGpu;
    }

    /**
     * Pools for an APU / integrated-graphics-only build: air cooling.
     *
     * @param  array<string, mixed>  $pools
     * @return array<string, mixed>
     */
    protected function integratedGpuPools(array $pools): array
    {
        $coolerPool = $pools['cooler'] ?? collect();

        $apu = $pools;
        $apu['cooler'] = $this->airCoolers($coolerPool);

        if ($apu['cooler']->isEmpty()) {
            $apu['cooler'] = $coolerPool;
        }

        return $apu;
    }

    /** Spinning-media drives are not part of a modern gaming build. */
    protected function isHardDrive(Component $component): bool
    {
        $hay = strtoupper(trim(
            (string) ($component->specs['type'] ?? '') . ' ' . (string) $component->name
        ));

        foreach (['HDD', 'HARD DRIVE', 'SPINNER', 'BARRACUDA', 'WD BLUE HDD'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wattage from a PSU: the dedicated column, then an explicit spec, then
     * "N W" in the name, then the model number convention (PF400, A750GL,
     * RM850e). Unknown returns 0 so unverifiable parts pass the floor rather
     * than being wrongly rejected.
     *
     * THE COLUMN IS THE AUTHORITY (fixed 2026-10-07).
     *
     * This used to read specs and the name only, and ignored
     * `components.wattage` - which the identity backfill populates on 282 of
     * 287 PSUs. The consequence was not cosmetic: "SeaSonic Focus GX V4 ATX 3
     * (2024)" has no wattage in its name and no specs, so it resolved to 0W and
     * ensurePowerAdequacy() and the resolution PSU floor were both reasoning
     * about a PSU whose real output they had already been given. The row that
     * cannot read a wattage is the one that most needs the column, because it
     * is exactly the row the name-based fallbacks cannot help with.
     */
    protected function psuWattage(Component $component): int
    {
        $specs = $component->specs ?? [];

        $wattage = (int) ($component->wattage ?? 0);

        if ($wattage <= 0) {
            $wattage = (int) ($specs['wattage'] ?? ($specs['power'] ?? 0));
        }

        if ($wattage > 0) {
            return $wattage;
        }

        $name = strtoupper((string) $component->name);

        if (preg_match('/(\d{3,4})\s*W/i', $name, $m) === 1) {
            return (int) $m[1];
        }

        // Model-number convention: walk every 3-4 digit run and return the
        // first plausible PSU wattage (300-1600W). This catches the real
        // catalogue spellings â€” PL750D, P750BS, P850GM, A750BN, RM650e,
        // CX550, UD750GM â€” where the wattage digits sit mid-model with
        // letters on both sides. A 230V voltage mark and 4-digit years
        // (2023, 2024) naturally fall out of the plausible range, so they can
        // never be misread as wattage. Unknown returns 0, which filters the
        // part OUT of floor checks (fail-safe: we never claim a wattage we
        // cannot read, so an unreadable PSU is never wrongly accepted).
        if (preg_match_all('/(\d{3,4})/', $name, $m) > 0) {
            foreach ($m[1] as $run) {
                $value = (int) $run;

                if ($value >= 300 && $value <= 1600) {
                    return $value;
                }
            }
        }

        return 0;
    }

    /**
     * Minimum PSU wattage a build must ship with (boss directive 2026-09-27):
     * - any 1080p system: at least 750W
     * - any 1440p system: at least 850W
     * - any 4K system: at least 1000W
     * - ALWAYS checked against the GPU's own hardware requirement (e.g. an
     *   RX 9070 XT needs min 750W per AMD's spec â€” so a 9070 XT at 1080p is
     *   still 750W, and a 9070 XT at 1440p is 850W, not the GPU's 750W).
     * The returned floor is the HIGHER of the resolution floor and the GPU's
     * manufacturer-recommended minimum, so no pick can ever under-power a GPU.
     */
    protected function minPsuFor(?string $resolution, ?array $gpuSelection): int
    {
        $res = strtoupper((string) $resolution);

        $resolutionFloor = match (true) {
            str_contains($res, '4K'), str_contains($res, '2160') => 1000,
            str_contains($res, '1440'), $res === '1440P' => 850,
            default => 750,
        };

        $gpuMin = $this->gpuRecommendedPsu((string) ($gpuSelection['name'] ?? ''));

        return max($resolutionFloor, $gpuMin);
    }

    /**
     * Manufacturer-recommended minimum PSU wattage for a GPU model (checked
     * against hardware spec sheets, e.g. AMD RX 9070 XT = 750W, NVIDIA
     * RTX 5080 = 850W). Unknown GPUs return 0 so the resolution floor alone
     * still applies. These are the RECOMMENDED SYSTEM PSU sizes, not board
     * TDP: they include headroom for the whole platform.
     */
    protected function gpuRecommendedPsu(string $name): int
    {
        $h = strtoupper($name);

        $known = [
            // Current-gen NVIDIA (RTX 50-series).
            '/\bRTX 5090\b/' => 1000,
            '/\bRTX 5080\b/' => 850,
            '/\bRTX 5070 TI\b/' => 750,
            '/\bRTX 5070\b/' => 650,
            '/\bRTX 5060 TI\b/' => 550,
            '/\bRTX 5060\b/' => 550, // 500W board spec; 550 gives honest headroom
            // Current-gen AMD (RX 9000-series).
            '/\bRX 9070 XT\b/' => 750,
            '/\bRX 9070\b/' => 650,
            '/\bRX 9060 XT\b/' => 550,
            '/\bRX 9060\b/' => 500,
            // Previous-gen NVIDIA.
            '/\bRTX 4090\b/' => 1000,
            '/\bRTX 4080 SUPER\b/' => 850,
            '/\bRTX 4080\b/' => 850,
            '/\bRTX 4070 TI SUPER\b/' => 750,
            '/\bRTX 4070 TI\b/' => 750,
            '/\bRTX 4070 SUPER\b/' => 650,
            '/\bRTX 4070\b/' => 650,
            '/\bRTX 4060 TI\b/' => 550,
            '/\bRTX 4060\b/' => 550,
            '/\bRTX 3090 TI\b/' => 850,
            '/\bRTX 3090\b/' => 850,
            '/\bRTX 3080 TI\b/' => 850,
            '/\bRTX 3080\b/' => 750,
            '/\bRTX 3070 TI\b/' => 750,
            '/\bRTX 3070\b/' => 650,
            '/\bRTX 3060 TI\b/' => 600,
            '/\bRTX 3060\b/' => 550,
            '/\bRTX 3050\b/' => 450,
            // Previous-gen AMD.
            '/\bRX 7900 XTX\b/' => 800,
            '/\bRX 7900 XT\b/' => 750,
            '/\bRX 7900 GRE\b/' => 700,
            '/\bRX 7800 XT\b/' => 700,
            '/\bRX 7700 XT\b/' => 650,
            '/\bRX 7600 XT\b/' => 550,
            '/\bRX 7600\b/' => 550,
            '/\bRX 6950 XT\b/' => 850,
            '/\bRX 6900 XT\b/' => 850,
            '/\bRX 6800 XT\b/' => 750,
            '/\bRX 6800\b/' => 650,
            '/\bRX 6750 XT\b/' => 650,
            '/\bRX 6700 XT\b/' => 650,
            '/\bRX 6600 XT\b/' => 500,
            '/\bRX 6600\b/' => 500,
            // Intel Arc.
            '/\bARC B580\b/' => 600,
            '/\bARC A770\b/' => 650,
            '/\bARC A750\b/' => 600,
            '/\bARC A580\b/' => 550,
            '/\bARC A380\b/' => 450,
        ];

        foreach ($known as $pattern => $minPsu) {
            if (preg_match($pattern, $h) === 1) {
                return $minPsu;
            }
        }

        return 0;
    }

    /**
     * Internal wattage estimate used to size the PSU safely:
     * explicit DB column first, then the GPU reference-TDP map (the catalogue
     * has no wattage for 99% of GPUs), then the PSU model-number parser.
     */
    protected function componentWattage(string $slug, Component $component): int
    {
        $explicit = (int) ($component->wattage ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        return match ($slug) {
            'gpu' => $this->gpuTdp((string) $component->name),
            'psu' => $this->psuWattage($component),
            default => 0,
        };
    }

    /**
     * Reference TDP (board power) for known dedicated GPUs, used to pick a PSU
     * with real headroom. Values are the published reference/typical figures
     * (e.g. RX 7900 XTX â‰ˆ 355W, RTX 5090 â‰ˆ 575W). Unknown models return 0 so
     * the power pass simply skips them rather than rejecting a build.
     */
    protected function gpuTdp(string $name): int
    {
        $h = strtoupper($name);

        $known = [
            '/\bRTX 5090\b/' => 575,
            '/\bRTX 5080\b/' => 360,
            '/\bRTX 5070 TI\b/' => 300,
            '/\bRTX 5070\b/' => 250,
            '/\bRTX 5060 TI\b/' => 180,
            '/\bRTX 5060\b/' => 145,
            '/\bRTX 4090\b/' => 450,
            '/\bRTX 4080 SUPER\b/' => 320,
            '/\bRTX 4080\b/' => 320,
            '/\bRTX 4070 TI SUPER\b/' => 285,
            '/\bRTX 4070 TI\b/' => 285,
            '/\bRTX 4070 SUPER\b/' => 220,
            '/\bRTX 4070\b/' => 200,
            '/\bRTX 4060 TI\b/' => 160,
            '/\bRTX 4060\b/' => 115,
            '/\bRTX 3090 TI\b/' => 450,
            '/\bRTX 3090\b/' => 350,
            '/\bRTX 3080 TI\b/' => 350,
            '/\bRTX 3080\b/' => 320,
            '/\bRTX 3070 TI\b/' => 290,
            '/\bRTX 3070\b/' => 220,
            '/\bRTX 3060 TI\b/' => 200,
            '/\bRTX 3060\b/' => 170,
            '/\bRTX 3050\b/' => 130,
            '/\bRTX 2080 TI\b/' => 250,
            '/\bRTX 2070 SUPER\b/' => 215,
            '/\bRTX 2070\b/' => 175,
            '/\bRTX 2060 SUPER\b/' => 175,
            '/\bRTX 2060\b/' => 160,
            '/\bRTX 1660 SUPER\b/' => 125,
            '/\bRTX 1660 TI\b/' => 120,
            '/\bRTX 1660\b/' => 120,
            '/\bGTX 1080 TI\b/' => 250,
            '/\bGTX 1080\b/' => 180,
            '/\bGTX 1070\b/' => 150,
            '/\bGTX 1060\b/' => 120,
            '/\bGTX 1050 TI\b/' => 75,
            '/\bRX 7900 XTX\b/' => 355,
            '/\bRX 7900 XT\b/' => 315,
            '/\bRX 7900 GRE\b/' => 260,
            '/\bRX 7800 XT\b/' => 263,
            '/\bRX 7700 XT\b/' => 245,
            '/\bRX 7600 XT\b/' => 148,
            '/\bRX 7600\b/' => 165,
            '/\bRX 6950 XT\b/' => 335,
            '/\bRX 6900 XT\b/' => 300,
            '/\bRX 6800 XT\b/' => 300,
            '/\bRX 6800\b/' => 250,
            '/\bRX 6750 XT\b/' => 250,
            '/\bRX 6700 XT\b/' => 230,
            '/\bRX 6600 XT\b/' => 160,
            '/\bRX 6600\b/' => 132,
            '/\bRX 5700 XT\b/' => 225,
            '/\bRX 5700\b/' => 180,
            '/\bRX 5600 XT\b/' => 160,
            '/\bRX 5500 XT\b/' => 130,
            '/\bRX VEGA 64\b/' => 295,
            '/\bRX VEGA 56\b/' => 210,
            '/\bRX 580\b/' => 185,
            '/\bRX 570\b/' => 150,
            '/\bARC B580\b/' => 190,
            '/\bARC A770\b/' => 225,
            '/\bARC A750\b/' => 225,
            '/\bARC A580\b/' => 185,
            '/\bARC A380\b/' => 75,
        ];

        foreach ($known as $pattern => $tdp) {
            if (preg_match($pattern, $h) === 1) {
                return $tdp;
            }
        }

        return 0;
    }

    /**
     * Guarantee every shipped build is physically powered: required wattage is
     * CPU draw + GPU draw + 200W headroom (same convention as the database's
     * `wattage_sufficient` rule). If the picked PSU is too small, upgrade to
     * the cheapest unit in the catalogue that can do the job. The upgrade may
     * push the build over budget â€” decorateBuild explains that honestly.
     *
     * @param  array<string, array<string, mixed>>  $selection
     * @param  array<string, \Illuminate\Support\Collection<int, Component>>  $pools
     * @return array<string, array<string, mixed>>
     */
    protected function ensurePowerAdequacy(array $selection, array $pools, ?string $resolution = null): array
    {
        $cpuW = (int) ($selection['cpu']['wattage'] ?? 0);
        $gpuW = (int) ($selection['gpu']['wattage'] ?? 0);

        // Boss directive (2026-09-27): the PSU must ALWAYS be at least the
        // resolution floor (750W on 1080p, 850W on 1440p, 1000W on 4K) AND at
        // least the GPU's own manufacturer minimum (e.g. RX 9070 XT = 750W),
        // even when we cannot estimate CPU/GPU draw precisely. The floor is
        // effectively enforced earlier (PSU pool filtered in every pick path),
        // but this backstop re-check guards every future picker too.

        $need = max(
            $cpuW + $gpuW + 200,
            $this->minPsuFor($resolution, $selection['gpu'] ?? null)
        );

        $psu = $selection['psu'] ?? null;
        $psuW = (int) ($psu['wattage'] ?? 0);

        if ($psu !== null && $psuW >= $need) {
            return $selection;
        }

        $pool = $pools['psu'] ?? collect();
        if ($pool->isEmpty()) {
            return $selection;
        }

        $better = $pool
            ->filter(fn (Component $component) => $this->psuWattage($component) >= $need)
            ->sortBy('price')
            ->first();

        if ($better === null) {
            // Nothing in the catalogue can safely power this GPU combo â€” keep
            // the original pick (decorateBuild's honest explanation covers it).
            return $selection;
        }

        $selection['psu'] = [
            'id' => $better->id,
            'name' => $better->name,
            'price' => (float) $better->price,
            'score' => $better->score,
            'wattage' => $this->psuWattage($better),
        ];

        return $selection;
    }

    /**
     * The TRUE socket for a CPU, derived from the model name rather than the
     * catalogue's `socket` column (which has proven unreliable â€” e.g. AMD
     * 3000/4000G/5000-series parts mislabelled as AM5). Knowing the real
     * socket is the foundation of every compatibility gate (motherboard,
     * cooler, RAM generation), so this must never trust dirty data when the
     * model number is recognisable.
     */
    protected function canonicalSocket(Component $component): ?string
    {
        $name = strtoupper((string) $component->name);
        $h = $name;

        // --- AMD desktop -------------------------------------------------
        if (preg_match('/(?:RYZEN|ATHLON)/', $h) === 1) {
            // Threadripper (TR4/sTRX4/sWRX8) â€” rare in consumer catalogue but
            // a recognisable AM-series exception; keep their own socket.
            if (preg_match('/THREADRIPPER/', $h) === 1) {
                return str_contains($h, 'AI MAX') ? null : ($component->socket ?: null);
            }

            // Ryzen-series model number. The catalogue is inconsistent: most
            // rows read "Ryzen 7 7800X3D" (series + model) but a few drop the
            // series entirely and read "Ryzen 7800X3D", which the old
            // `RYZEN \d (\d{4})` pattern could not match â€” leaving those parts
            // with no socket at all. The series digit is therefore optional.
            if (preg_match('/\bRYZEN\s+\d?\s?(\d{4})/', $h, $m) === 1) {
                $model = (int) $m[1];

                // Ryzen 7 8700G is the one 8000-series part on the older AM4
                // socket; 8500G/8600G are genuinely AM5. The numeric rule below
                // would call 8700 AM5 and pair it with boards it cannot boot on.
                if ($model === 8700) {
                    return 'AM4';
                }

                // 7000/8000G/9000 series are AM5; 1000-5000 series are AM4.
                return ($model >= 6000) ? 'AM5' : 'AM4';
            }

            // "Ryzen 5 PRO 5650G" style.
            if (preg_match('/\bRYZEN\s+\d\s+PRO\s+(\d{4})/', $h, $m) === 1) {
                return ((int) $m[1] >= 6000) ? 'AM5' : 'AM4';
            }

            // Athlon with a G-series / 200GE-3000G naming â†’ AM4.
            if (preg_match('/\b(?:200GE|220GE|240GE|3000G|320GE|240GE)\b/', $h) === 1) {
                return 'AM4';
            }

            // Generic fallback â€” do not trust the dirty column for AMD.
            return null;
        }

        // --- Intel desktop -------------------------------------------------
        if (preg_match('/(?:CORE ULTRA|CORE I|PENTIUM|CELERON)/', $h) === 1) {
            // Arrow Lake: Core Ultra 5/7/9 2xx â†’ LGA1851.
            if (preg_match('/CORE ULTRA \d \d{3}/', $h) === 1) {
                return 'LGA1851';
            }

            // Core i3/i5/i7/i9: 12th-14th gen (12xxx-14xxx) â†’ LGA1700.
            if (preg_match('/CORE I\d[- ]?(\d{5})/', $h, $m) === 1) {
                $generation = (int) substr($m[1], 0, 2);
                if ($generation >= 12) {
                    return 'LGA1700';
                }
                if ($generation >= 10) {
                    return 'LGA1200';
                }
                if ($generation >= 6) {
                    return 'LGA1151';
                }
                return 'LGA1150';
            }

            // Pentium/Celeron with a G-number (G4400, G7400...) â†’ follow the
            // same generation ladder by family number when recognisable.
            if (preg_match('/(?:PENTIUM|CELERON).*?\bG(\d{4})/', $h, $m) === 1) {
                $family = (int) $m[1];
                if ($family >= 6900) {
                    return 'LGA1700';
                }
                if ($family >= 5000) {
                    return 'LGA1200';
                }
                return 'LGA1151';
            }

            return $component->socket;
        }

        // Unknown / legacy â€” return the raw value so a human-readable socket
        // still appears, but the picker's viablePool() floor (require a
        // non-empty canonical socket here) already stops unknown-socket CPUs
        // from ever shipping in a complete build.
        return $component->socket;
    }

    /**
     * Rough CPU brand from a picked component (used for cooler sanity checks).
     */
    protected function cpuBrandOf(?array $cpu): string
    {
        $name = strtoupper((string) ($cpu['name'] ?? ''));

        if ($name === '') {
            return 'unknown';
        }

        if (str_contains($name, 'INTEL') || str_contains($name, 'CORE I') || str_contains($name, 'PENTIUM') || str_contains($name, 'CELERON')) {
            return 'intel';
        }

        return str_contains($name, 'AMD') ? 'amd' : 'unknown';
    }

    /**
     * Whether a cooler can pair with a CPU brand. Branded stock coolers
     * (AMD Wraith, Intel Laminar/stock) are restricted to their own brand;
     * aftermarket coolers fit anything.
     */
    protected function coolerFitsBrand(Component $cooler, string $cpuBrand): bool
    {
        $name = strtoupper((string) $cooler->name);

        $isIntelBranded = str_contains($name, 'LAMINAR') || (str_contains($name, 'INTEL') && ! str_contains($name, 'ARCTIC'));
        $isAmdBranded = str_contains($name, 'WRATH') || (str_contains($name, 'AMD') && ! str_contains($name, 'ARCTIC'));

        if ($isIntelBranded) {
            return $cpuBrand === 'intel';
        }

        if ($isAmdBranded) {
            return $cpuBrand === 'amd';
        }

        return true; // Aftermarket cooler â€” fits both CPUs.
    }
}
