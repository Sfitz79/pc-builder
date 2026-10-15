<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PCTG Business must be a real product line at the entry point, not a page of
 * marketing copy that drops the buyer into the gaming configurator.
 *
 * THE DEFECT THIS COVERS
 * ----------------------
 * Every "Start a build" / "Configure one" / "Open the builder" link on /business
 * pointed at route('builder'), which renders the same view with a hardcoded
 * four-button GAMING scenario picker (Gaming / Streaming / Content Creation /
 * AI Development). A buyer who had just read "Enterprise Servers" and "NAS &
 * Storage" arrived at a page asking them to choose a game.
 *
 * Worse, the business scenarios could not have worked even if the page had
 * offered them: BuilderController::ai() validated `purpose` against
 * Rule::in(['gaming', 'streaming', 'creation', 'ai']), so every business slug -
 * studio, rendering, enterprise, server, nas, home-business - was rejected with
 * a 422 before the recommendation engine ever saw it, even though
 * config/workloads.php, GeminiService and AIRecommendationService all already
 * understood those slugs.
 *
 * These tests assert the whole chain: link, route, render, payload, and the
 * fail-closed behaviour when the URL is edited.
 */
class BusinessBuilderEntryPointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function businessWorkloadSlugs(): array
    {
        return array_keys(GeminiService::businessWorkloads());
    }

    /**
     * Render a view for string assertions without tripping PHPUnit's risky flag.
     *
     * layouts.seo - reached through @include('navigation-menu') - leaves an
     * output buffer open when Blade renders it. That is a pre-existing quirk of
     * this layout, not something this change introduced, and it is not a
     * failure signal: the assertions all evaluate normally. It does mean any
     * test that renders that layout is reported risky, so the buffer this render
     * added is closed again here.
     *
     * Only buffers opened by THIS render are closed, never one PHPUnit or the
     * framework opened before it.
     */
    private function renderView(string $view): string
    {
        return $this->withoutLeakedBuffers(fn () => view($view)->render());
    }

    /**
     * Fetch a page through the HTTP kernel and return its HTML, buffer-safe.
     *
     * Use this rather than renderView() whenever the page under test reads the
     * CURRENT ROUTE - navigation-menu resolves the builder link from
     * request()->routeIs(), which a bare view render cannot satisfy.
     */
    private function getPage(string $uri): string
    {
        return $this->withoutLeakedBuffers(
            fn () => $this->get($uri)->assertOk()->getContent()
        );
    }

    private function withoutLeakedBuffers(callable $render): string
    {
        $level = ob_get_level();

        try {
            return $render();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function test_the_shared_nav_does_not_bounce_a_business_buyer_back_to_the_gaming_builder(): void
    {
        /*
         * THE LAST ROUTE OFF THE BUSINESS TAB.
         *
         * The three build buttons on /business were fixed, but navigation-menu
         * is shared by every page and its "PC Builder" item still pointed at
         * /builder. So a buyer who read "Start a build", then reached for the
         * nav sitting beside it, was dropped straight back into the gaming
         * wizard. The nav item is now resolved from the current route.
         */
        $business = $this->getPage('/business');
        $this->assertStringContainsString(
            'Business Builder',
            $business,
            'on /business the nav builder item should be the business builder'
        );

        // And it must not have leaked onto the gaming side.
        $gaming = $this->getPage('/');
        $this->assertStringContainsString(
            'PC Builder',
            $gaming,
            'the main site nav must still call the gaming builder "PC Builder"'
        );
    }

    public function test_no_build_button_on_the_business_page_points_at_the_gaming_builder(): void
    {
        /*
         * CHECKED PER BUTTON, not by scanning for "/builder" anywhere.
         *
         * The shared navigation legitimately links to /builder - it is the same
         * nav every page carries and it should reach the main configurator. A
         * blanket scan therefore fails on chrome that is correct. What must not
         * happen is a BUILD call to action on the business page sending a
         * business buyer to the gaming configurator.
         *
         * Asserted through the view rather than the HTTP kernel: rendering
         * layouts.seo through the kernel leaves an output buffer open, which
         * PHPUnit reports as a risky test even though every assertion ran. The
         * same markup is asserted, without the buffer noise.
         */
        $html = $this->renderView('business.index');

        $ctas = ['Start a build', 'Configure one', 'Open the builder'];

        foreach ($ctas as $label) {
            $pattern = '/<a\s+href="([^"]+)"[^>]*>\s*' . preg_quote($label, '/') . '/';

            $this->assertSame(
                1,
                preg_match($pattern, $html, $matches),
                "the '{$label}' button is missing from /business"
            );

            /*
             * Build OR contact. This used to assert "/business/build" outright,
             * which was true only while every CTA produced a one-click build -
             * including for products we cannot assemble. "Configure one"
             * describes a dual professional-GPU render node, and routing it
             * straight at the builder is a build button for something the
             * catalogue cannot build. Both destinations are correct; the
             * GAMING builder is the only one that is not.
             */
            $this->assertTrue(
                str_contains($matches[1], '/business/build')
                    || str_contains($matches[1], '/support'),
                "the '{$label}' button on /business points at '{$matches[1]}', which is neither the "
                .'business builder nor contact'
            );

            $this->assertDoesNotMatchRegularExpression(
                '#/builder(?:"|\?|$)#',
                $matches[1],
                "the '{$label}' button on /business still points at the GAMING builder"
            );
        }
    }

    public function test_every_pillar_card_leads_somewhere_honest(): void
    {
        // See test_no_build_button... for why this reads the view directly.
        $html = $this->renderView('business.index');

        /*
         * REWRITTEN. This used to assert that all eight pillars link to
         * /business/build. It now asserts the thing that actually matters:
         * every pillar goes to a build button OR to contact, never to a build
         * button for something we cannot assemble.
         *
         * A blanket "all pillars build" assertion was only ever true while the
         * business builder would happily quote a gaming PC as a NAS, which is
         * the defect the whole catalogue split exists to remove. Asserting eight
         * build links would have re-encoded it.
         */
        preg_match_all('/<a\s+href="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);

        $targets = [];
        foreach ($matches as $m) {
            $href = $m[1];
            if (str_contains($href, '/business/build') || str_contains($href, '/support?workload=')) {
                $targets[] = $href;
            }
        }

        $this->assertGreaterThanOrEqual(
            8,
            count($targets),
            'every "Who these are for" pillar should link into the business builder or to contact'
        );

        foreach ($targets as $href) {
            $this->assertDoesNotMatchRegularExpression(
                '#/business/build\?purpose=(nas|enterprise|server|rendering)$#',
                $href,
                "a workload the catalogue cannot build is still offered as a one-click build: {$href}"
            );
        }
    }

    public function test_the_business_builder_renders_business_scenarios_not_gaming_ones(): void
    {
        $this->seedCatalogue();

        $html = $this->get('/business/build')->assertOk()->getContent();

        $partition = app(\App\Services\BusinessCapabilityGate::class)->partition();

        // Every workload the catalogue can build gets a scenario button...
        foreach (array_keys($partition['buildable']) as $slug) {
            $this->assertStringContainsString(
                $slug,
                $html,
                "buildable workload '{$slug}' is missing from the business scenario picker"
            );
        }

        // ...and every one it cannot gets named as a conversation instead, with
        // the reason. Silently omitting it would hide the range; offering a
        // build button would be a lie we could not keep.
        foreach ($partition['spec_only'] as $slug => $workload) {
            $this->assertStringNotContainsString(
                "purpose = " . json_encode($slug),
                $html,
                "'{$slug}' cannot be built but is still offered as a one-click build"
            );
            $this->assertStringContainsString(
                'workload=' . urlencode($slug),
                $html,
                "'{$slug}' should link to contact so the buyer can still reach us for it"
            );
        }

        // The gaming picker's four buttons and their copy must be gone.
        $this->assertStringNotContainsString('🎮 Gaming', $html);
        $this->assertStringNotContainsString('🤖 AI Development', $html);
        $this->assertStringNotContainsString('Build Your Next PC', $html);
    }

    public function test_a_workload_we_cannot_build_is_never_pre_selected(): void
    {
        $this->seedCatalogue();

        $partition = app(\App\Services\BusinessCapabilityGate::class)->partition();
        $buildable = array_keys($partition['buildable']);

        $this->assertNotEmpty($buildable, 'the fixture should make at least one workload buildable');

        foreach (array_keys($partition['spec_only']) as $slug) {
            $html = $this->get('/business/build?purpose=' . $slug)->assertOk()->getContent();

            // Plain string search, not a regex: PCRE2 has no \u escape, and
            // doubling the backslashes again buys nothing here.
            $needle = '\u0022purpose\u0022:\u0022';
            $at = strpos($html, $needle);
            $seeded = '';

            if ($at !== false) {
                $rest = substr($html, $at + strlen($needle), 128);
                $seeded = substr($rest, 0, (int) strpos($rest, '\u0022'));
            }

            $this->assertNotSame(
                $slug,
                $seeded,
                "/business/build?purpose={$slug} pre-selected a workload we cannot build"
            );

            $this->assertContains(
                $seeded,
                $buildable,
                'the fallback must be a buildable workload, not an arbitrary one'
            );
        }
    }

    public function test_the_business_builder_carries_the_business_palette_and_no_fps_claim(): void
    {
        $html = $this->get('/business/build')->assertOk()->getContent();

        $this->assertStringContainsString('data-pctg-theme="biz"', $html);
        $this->assertStringContainsString('data-segment="business"', $html);

        // Frames-per-second is a gaming claim derived from a gaming GPU tier
        // ladder and a display resolution. It is meaningless for a workstation,
        // a server or a NAS, so it must not be offered at all.
        $this->assertStringNotContainsString('Expected FPS', $html);
        $this->assertStringNotContainsString('pctg-fps-pop', $html);
    }

    public function test_the_business_builder_does_not_ask_for_a_display_resolution(): void
    {
        $html = $this->get('/business/build')->assertOk()->getContent();

        // config/workloads.php sets segments.business.resolutionRelevant = false
        // and businessGuardrails.ignoreResolutionBand = true.
        $this->assertStringNotContainsString('id="pctg-resolution"', $html);
        $this->assertStringNotContainsString('Screen resolution', $html);
    }

    public function test_the_gaming_builder_is_unchanged(): void
    {
        $html = $this->get('/builder')->assertOk()->getContent();

        $this->assertStringContainsString('🎮 Gaming', $html);
        $this->assertStringContainsString('Build Your Next PC', $html);
        $this->assertStringContainsString('id="pctg-resolution"', $html);
        $this->assertStringContainsString('Expected FPS', $html);
        $this->assertStringContainsString('data-pctg-theme="gaming"', $html);
    }

    /**
     * @dataProvider requestedWorkloads
     */
    public function test_the_requested_business_scenario_is_pre_selected(string $requested): void
    {
        $this->seedCatalogue();

        // A human form ("Home Business") resolves to a slug before anything
        // else happens, so it is the slug that is looked up and asserted on.
        $slug = GeminiService::resolveBusinessWorkload($requested);

        $partition = app(\App\Services\BusinessCapabilityGate::class)->partition();

        // Only a BUILDABLE workload may be pre-selected. The unbuildable case is
        // asserted separately, in
        // test_a_workload_we_cannot_build_is_never_pre_selected.
        $this->assertArrayHasKey(
            $slug,
            $partition['buildable'],
            "test data problem: '{$requested}' resolves to '{$slug}', which is not buildable with this fixture"
        );

        $html = $this->get('/business/build?purpose=' . urlencode($requested))->assertOk()->getContent();

        // The seeded Alpine state is the source of truth for what gets quoted,
        // so assert on it rather than on which card happens to be ringed.
        //
        // The payload is HTML-attribute escaped (it rides inside a single-quoted
        // JSON.parse argument), so the quotes arrive as 0022 escapes. Matching
        // the raw form here would pass on a page that had not actually seeded
        // the workload.
        $this->assertStringContainsString(
            '\u0022purpose\u0022:\u0022' . $slug . '\u0022',
            $html,
            "requesting '{$requested}' did not seed the builder with '{$slug}'"
        );
    }

    public static function requestedWorkloads(): array
    {
        // Buildable with the test fixture, which stocks an air cooler (quiet)
        // and nothing else the business capabilities ask for. rendering is NOT
        // here because it needs a professional GPU, and the fixture's cards are
        // plainly named test parts.
        return [
            'studio' => ['studio'],
            'home-business' => ['home-business'],
            'live-production' => ['live-production'],
            'human form' => ['Home Business'],
        ];
    }

    /**
     * Rule 6: unknown must never equal permitted, and a URL a customer can edit
     * must not be able to turn the business product line into a gaming one.
     *
     * @dataProvider hostilePurposeValues
     */
    public function test_an_edited_url_cannot_produce_a_gaming_build(string $requested): void
    {
        $resolved = GeminiService::resolveBusinessWorkload($requested);

        $this->assertNotSame('gaming', $resolved);
        $this->assertArrayHasKey(
            $resolved,
            GeminiService::businessWorkloads(),
            "requested '{$requested}' resolved to '{$resolved}', which is not a business workload"
        );
    }

    public static function hostilePurposeValues(): array
    {
        return [
            'gaming' => ['gaming'],
            'streaming' => ['streaming'],
            'creation' => ['creation'],
            'unknown' => ['totally-not-a-workload'],
            'sql-ish' => ["studio' OR 1=1--"],
            'empty' => [''],
        ];
    }

    /*
     * ---------------------------------------------------------------------
     * THE OUTCOME TESTS
     *
     * Everything above proves the ENTRY POINT is right: the links, the route,
     * the payload, the fail-closed resolution. None of it proves the QUOTE is
     * different, and that distinction cost a production deploy.
     *
     * The first version of this fix scaled each component pool's score by the
     * workload's declared weights when Gemini was unavailable. That was inert:
     * multiplying a whole pool by one scalar cannot reorder it, and the picker
     * takes the best-scoring part that fits a per-category budget ALLOWANCE,
     * and that allowance came from a hardcoded gaming table (gpu 0.40).
     *
     * Measured on production after shipping it: a GBP 2,500 NAS request and a
     * GBP 2,500 gaming request returned byte-identical builds - RTX 5070, 360mm
     * AIO, RGB NZXT case, GBP 2,177.44 both times. The blue panels were real;
     * the machine behind them was still a gaming one.
     *
     * These assert the BUILD, not the mechanism, so the same class of
     * plausible-but-inert change cannot pass again.
     * ---------------------------------------------------------------------
     */

    /** @return array<string, array{id: int, name: string, price: float}> */
    private function quoteFor(string $purpose, float $budget = 2500.0): array
    {
        $this->seedCatalogue();

        return $this->postJson('/builder/ai', [
            'budget' => $budget,
            'purpose' => $purpose,
        ])->assertOk()->json('budget.components');
    }

    /**
     * A minimal but COMPLETE and legal catalogue, with two price points in the
     * two categories the business weights disagree about.
     *
     * The cheap and dear graphics card, and the small and large drive, are what
     * make the outcome assertions meaningful: a workload that moves money must
     * land on a different part from the same pool. Without two options in each,
     * "the build changed" would be unprovable and "the build did not change"
     * would be unfalsifiable.
     *
     * Prices are evidenced, because PriceIntegrityService::clean() drops any row
     * with no source_url or a stale price_checked_at - the same requirement live
     * catalogue rows carry.
     */
    private function seedCatalogue(): void
    {
        if (\App\Models\Category::count() > 0) {
            return;
        }

        $cats = [];
        foreach ([
            'cpu' => 'Processors',
            'motherboard' => 'Motherboards',
            'ram' => 'Memory',
            'storage' => 'Storage',
            'gpu' => 'Graphics Cards',
            'psu' => 'Power Supplies',
            'case' => 'Cases',
            'cooler' => 'CPU Coolers',
        ] as $slug => $name) {
            $cats[$slug] = \App\Models\Category::create(['name' => $name, 'slug' => $slug]);
        }

        $maker = \App\Models\Manufacturer::create([
            'name' => 'PCTechGuy Test',
            'slug' => 'pctechguy-test',
        ]);

        $part = function (string $slug, string $name, float $price, array $specs = [], array $extra = []) use ($cats, $maker) {
            return \App\Models\Component::create(array_merge([
                'category_id' => $cats[$slug]->id,
                'manufacturer_id' => $maker->id,
                'name' => $name,
                'slug' => strtolower(str_replace([' ', '/'], '-', $name)),
                'price' => $price,
                'currency' => 'GBP',
                'stock' => 25,
                'active' => true,
                'specs' => $specs,
                // Business-tagged. The business builder draws ONLY from
                // components carrying this tag, so an untagged fixture would
                // return an empty selection and every outcome test below would
                // pass for the wrong reason - or fail, which is worse, because
                // it would look like the catalogue split is broken.
                'segments' => ['gaming', 'business'],
                'source_url' => 'https://example.com/'.strtolower(str_replace([' ', '/'], '-', $name)),
                'price_checked_at' => now(),
            ], $extra));
        };

        // Eight cores clears GPU_CPU_COHERENCE, so both cards are reachable.
        $part('cpu', 'Test Ryzen 7 5700G', 149.99, ['cores' => 8, 'threads' => 16], ['socket' => 'AM4']);
        $part('motherboard', 'Test B550 Board', 119.99, ['memory_type' => 'DDR4'], ['socket' => 'AM4']);

        // 32GB is the MIN_RAM_GB spec floor and it carries `capacity`, the field
        // viablePool('ram') reads.
        $part('ram', 'Test 32 GB DDR4 3200', 79.99, ['capacity' => '32GB', 'speed' => 3200, 'type' => 'DDR4']);

        // TWO PRICE POINTS. MIN_STORAGE_GB is 500, so both clear the floor and
        // the difference is purely the allowance.
        $part('storage', 'Test 500GB NVMe', 39.99, ['capacity' => '500GB']);
        $part('storage', 'Test 2TB NVMe', 129.99, ['capacity' => '2TB']);

        // THREE PRICE POINTS, not two. With only two, studio (gpu share 0.144)
        // and gaming (0.40) both landed on the dear card, because unspent
        // budget from the earlier categories bridged the gap - and a test that
        // cannot see a difference is a test that proves nothing. The middle card
        // sits above studio's allowance and below gaming's, so each workload
        // resolves to a different one and the comparison becomes falsifiable.
        $part('gpu', 'Test Entry Card', 89.99, ['memory' => 4, 'chipset' => 'Test Entry']);
        $part('gpu', 'Test Mid Card', 199.99, ['memory' => 8, 'chipset' => 'Test Mid']);
        $part('gpu', 'Test Flagship Card', 449.99, ['memory' => 16, 'chipset' => 'Test Flagship']);

        $part('psu', 'Test 750W PSU', 69.99, ['wattage' => 750]);
        $part('case', 'Test Tower Case', 49.99);

        // Named for a real air cooler rather than "Test Air Cooler" on purpose.
        // The capability gate reads the PRODUCT NAME for quiet_cooling, exactly
        // as it does in production, so a fixture that did not match a recognised
        // air-cooler family would leave every workload unbuildable and the gate
        // tests would pass by accident - proving nothing while looking green.
        $part('cooler', 'Noctua NH-D15 chromax.black', 109.95);
    }

    public function test_a_nas_build_is_not_the_gaming_build(): void
    {
        $nas = $this->quoteFor('nas');
        $gaming = $this->quoteFor('gaming');

        $this->assertNotSame(
            array_column($gaming, 'name'),
            array_column($nas, 'name'),
            'a NAS build is identical to a gaming build - the workload is not reaching the picker'
        );

        // The specific failure config/workloads.php warns about in as many
        // words: "Do not let the generic scoring spend the budget on a
        // graphics card."
        $this->assertLessThan(
            $gaming['gpu']['price'],
            $nas['gpu']['price'],
            sprintf(
                'nas declares gpu => 5 and gaming gpu => 95, but nas still got the %s at GBP %s (gaming got the %s at GBP %s)',
                $nas['gpu']['name'],
                number_format($nas['gpu']['price'], 2),
                $gaming['gpu']['name'],
                number_format($gaming['gpu']['price'], 2)
            )
        );
    }

    public function test_the_gaming_build_spends_more_on_graphics_than_a_business_build(): void
    {
        /*
         * The end-to-end version of the boundary test: gaming keeps the
         * calibrated 40% graphics share, so it must still buy a materially
         * better card than a workload that declares almost no graphics.
         *
         * Asserted RELATIVE rather than against a fixed price. The picker takes
         * the best-SCORING card that fits the allowance, and scoring runs off
         * the real GPU tier ladder, so an absolute threshold here would be
         * asserting how synthetic fixture parts happen to tier. The invariant
         * worth protecting is the ordering, and that is what the declared
         * weights actually control.
         */
        $gaming = $this->quoteFor('gaming');
        $nas = $this->quoteFor('nas');

        $this->assertGreaterThan(
            $nas['gpu']['price'],
            $gaming['gpu']['price'],
            sprintf(
                'a gaming build (gpu => 95, 40%% calibrated share) got the %s at GBP %s; '
                .'a NAS build (gpu => 5) got the %s at GBP %s',
                $gaming['gpu']['name'],
                number_format($gaming['gpu']['price'], 2),
                $nas['gpu']['name'],
                number_format($nas['gpu']['price'], 2)
            )
        );
    }

    public function test_workloads_that_disagree_actually_disagree(): void
    {
        $rendering = $this->quoteFor('rendering');
        $nas = $this->quoteFor('nas');

        $this->assertNotSame(
            array_column($nas, 'name'),
            array_column($rendering, 'name'),
            'rendering (gpu => 97) and nas (gpu => 5) produced the same build'
        );

        // Opposite directions, so this catches a change that merely reshuffles
        // everything rather than one that respects the declared weights.
        $this->assertGreaterThan(
            $nas['gpu']['price'],
            $rendering['gpu']['price'],
            'rendering should spend MORE on graphics than nas, not less'
        );
    }

    public function test_workload_shares_sum_to_one_and_follow_the_declared_weights(): void
    {
        $shares = $this->workloadSharesFor('nas');

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.0001, 'shares must sum to the whole parts budget');

        $keys = array_keys($shares);
        sort($keys);
        $expected = array_keys(\App\Services\AIRecommendationService::BUDGET_SHARES);
        sort($expected);
        $this->assertSame($expected, $keys, 'every allocated category needs a share, or the picker falls back to 0.05');

        // The point of the whole change: a storage-bound workload moves the MONEY.
        $this->assertLessThan(0.05, $shares['gpu'], 'nas must not keep a meaningful share of the budget for graphics');
        $this->assertGreaterThan(0.10, $shares['storage'], 'nas must put real money into storage');

        // Categories nas is silent about (cooler, case, motherboard) get the
        // MEAN of what it did declare - not the picker's punitive 0.05 default,
        // which would leave three categories starved and let their floors, not
        // the workload, decide those parts.
        $declared = [70.0, 5.0, 75.0, 99.0, 70.0];
        $mean = array_sum($declared) / count($declared);
        $total = array_sum($declared) + ($mean * 3);

        $this->assertGreaterThan(0.05, $shares['cooler'], 'a silent category must not fall through to the 0.05 default');
        $this->assertEqualsWithDelta($mean / $total, $shares['cooler'], 0.0001);
    }

    public function test_no_gaming_workload_can_ever_claim_a_business_share(): void
    {
        /*
         * THE BLAST-RADIUS BOUNDARY.
         *
         * The published 1080p / 1440p / 4K entry prices are measured from the
         * live catalogue and quoted to customers. Business shares are a
         * different allocation of the same money, so letting one reach the
         * gaming picker would move those floors. This asserts the boundary
         * rather than asking anyone to trust the scoping comment.
         */
        $gamingSlugs = array_keys(array_filter(
            config('workloads.workloads', []),
            fn (array $spec) => ($spec['segment'] ?? 'gaming') === 'gaming'
        ));

        $this->assertNotEmpty($gamingSlugs, 'there should be gaming workloads to protect');

        foreach ($gamingSlugs as $slug) {
            $this->assertNull(
                $this->workloadSharesFor($slug),
                "gaming workload '{$slug}' must not claim business shares - it would move the published band floors"
            );
        }

        $this->assertNull($this->workloadSharesFor(null), 'no purpose at all must use the calibrated gaming table');
    }

    public function test_the_gaming_build_is_untouched_by_all_of_this(): void
    {
        // A real gaming quote still comes back complete, with a discrete GPU.
        // If the business shares had leaked onto this path the GPU allowance
        // would collapse and either the build would go incomplete or the card
        // would drop to the cheapest in the pool.
        $build = $this->quoteFor('gaming');

        $this->assertArrayHasKey('gpu', $build);
        $this->assertNotSame('Test Entry Card', $build['gpu']['name'] ?? null);
    }

    /** @return array<string, float>|null */
    private function workloadSharesFor(?string $purpose): ?array
    {
        $method = new \ReflectionMethod(
            app(\App\Services\AIRecommendationService::class),
            'workloadShares'
        );
        $method->setAccessible(true);

        return $method->invoke(app(\App\Services\AIRecommendationService::class), $purpose);
    }

    /*
     * ---------------------------------------------------------------------
     * THE CATALOGUE SPLIT
     *
     * The business builder must draw from a business catalogue, not from the
     * gaming one with a blue theme on it. These assert the split itself,
     * because a filter that silently stopped filtering would leave everything
     * looking fine - the gaming build would still be complete, still be priced,
     * still pass every existing test.
     * ---------------------------------------------------------------------
     */

    public function test_the_business_builder_cannot_see_an_untagged_component(): void
    {
        $this->seedCatalogue();

        // Untagged: the state of every component until someone puts it in the
        // business catalogue on purpose.
        $gamingOnly = \App\Models\Component::create([
            'category_id' => \App\Models\Category::where('slug', 'psu')->value('id'),
            'manufacturer_id' => \App\Models\Manufacturer::first()->id,
            'name' => 'Untagged Gaming PSU',
            'slug' => 'untagged-gaming-psu',
            'price' => 55.00,
            'currency' => 'GBP',
            'stock' => 10,
            'active' => true,
            'specs' => ['wattage' => 750],
            'source_url' => 'https://example.com/untagged',
            'price_checked_at' => now(),
        ]);

        $build = $this->quoteFor('nas');

        $this->assertNotSame(
            'Untagged Gaming PSU',
            $build['psu']['name'] ?? null,
            'an untagged component reached a business build - the catalogue split is not filtering'
        );
    }

    public function test_the_gaming_builder_still_sees_every_component_including_untagged_ones(): void
    {
        $this->seedCatalogue();

        $gamingOnly = \App\Models\Component::create([
            'category_id' => \App\Models\Category::where('slug', 'psu')->value('id'),
            'manufacturer_id' => \App\Models\Manufacturer::first()->id,
            'name' => 'Untagged Gaming PSU',
            'slug' => 'untagged-gaming-psu-2',
            'price' => 55.00,
            'currency' => 'GBP',
            'stock' => 10,
            'active' => true,
            'specs' => ['wattage' => 750],
            'source_url' => 'https://example.com/untagged-2',
            'price_checked_at' => now(),
        ]);

        $this->assertNull(
            $gamingOnly->segments,
            'the fixture must be untagged for this test to mean anything'
        );

        $build = $this->quoteFor('gaming');

        // This is the blast-radius boundary in the other direction: the gaming
        // pools do not filter on segments at all, so no existing component can
        // disappear from the gaming builder and the published band floors hold.
        $this->assertArrayHasKey('psu', $build);
    }

    public function test_an_empty_business_category_never_silently_refills_from_the_gaming_catalogue(): void
    {
        /*
         * The error path is where a filter like this usually leaks.
         *
         * buildPools() refills a category from scratch when a spec floor empties
         * it. That refill used to rebuild the pool from the WHOLE catalogue, so
         * on the business path a floor failure would have handed a workstation
         * quote a pool of RGB gaming cases. The refill now goes through the same
         * segment filter.
         */
        $this->seedCatalogue();

        // Strip the business tag from every RAM kit, so the business RAM pool is
        // empty and the floor-relaxation path is the only thing that can refill
        // it.
        \App\Models\Component::whereHas('category', fn ($q) => $q->where('slug', 'ram'))
            ->update(['segments' => null]);

        $build = $this->quoteFor('nas');

        $this->assertArrayNotHasKey(
            'ram',
            $build,
            'an empty business category was refilled from the gaming catalogue on the floor-relaxation path'
        );
    }

    public function test_the_business_catalogue_is_a_configured_decision_not_a_missing_table(): void
    {
        /*
         * NOT "the business catalogue is populated" - that is a statement about
         * production data, and asserting it here would either pass trivially on
         * an empty test database or fail for reasons that have nothing to do
         * with the code. It belongs in scripts/genie-curate-business-catalogue.php,
         * which reports the counts and prints the sample.
         *
         * What IS worth asserting is that an empty or unrecognised requirement
         * never reads as satisfied. That is the fail-closed half, and it is
         * pure logic, so it can be tested without a catalogue.
         */
        $gate = app(\App\Services\BusinessCapabilityGate::class);

        // A capability key that does not exist in config counts as MISSING, so a
        // typo in a workload's `requires` takes the build button away rather
        // than silently leaving it on.
        config([
            'workloads.capabilities' => ['quiet_cooling' => ['label' => 'quiet air cooling']],
            'workloads.workloads.fake-workload.requires' => ['quiet_cooling', 'not_a_real_capability'],
        ]);

        $verdict = $gate->verdict('fake-workload');

        $this->assertFalse($verdict['buildable'], 'an unrecognised requirement must not read as satisfied');
        $this->assertContains('not_a_real_capability', $verdict['unknown']);
        $this->assertContains('not_a_real_capability', $verdict['missing']);
        $this->assertContains('quiet_cooling', $verdict['required'], 'a declared requirement must not be dropped');

        // A workload that declares nothing has nothing missing.
        config(['workloads.workloads.fake-workload.requires' => []]);
        $this->assertTrue($gate->verdict('fake-workload')['buildable']);
    }

    public function test_component_segment_helpers_treat_null_as_gaming_only(): void
    {
        $null = new \App\Models\Component(['segments' => null]);
        $this->assertSame([], $null->productSegments());
        $this->assertFalse($null->servesSegment('business'));
        $this->assertFalse($null->servesSegment('gaming'));

        $both = new \App\Models\Component(['segments' => ['gaming', 'business']]);
        $this->assertTrue($both->servesSegment('business'));
        $this->assertTrue($both->servesSegment('gaming'));

        $empty = new \App\Models\Component(['segments' => []]);
        $this->assertSame([], $empty->productSegments(), 'an empty array means nobody claimed it');
        $this->assertFalse($empty->servesSegment('business'));

        // Case and whitespace, because the tag is written by a curation script.
        $messy = new \App\Models\Component(['segments' => ['  Business ', 'BUSINESS']]);
        $this->assertSame(['business'], $messy->productSegments());
    }
}
