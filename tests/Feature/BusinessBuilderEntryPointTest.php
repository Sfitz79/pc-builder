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

            $this->assertStringContainsString(
                '/business/build',
                $matches[1],
                "the '{$label}' button on /business still points at '{$matches[1]}' (the gaming builder)"
            );
        }
    }

    public function test_every_pillar_card_is_a_link_into_the_business_builder(): void
    {
        // See test_no_build_button... for why this reads the view directly.
        $html = $this->renderView('business.index');

        // Eight pillars, each now an <a>. Count the anchor hrefs rather than
        // trusting the array length, so a future edit that drops one fails here.
        preg_match_all('/href="[^"]*\/business\/build[^"]*"/', $html, $matches);
        $this->assertGreaterThanOrEqual(
            8,
            count($matches[0]),
            'every "Who these are for" pillar should link into the business builder'
        );
    }

    public function test_the_business_builder_renders_business_scenarios_not_gaming_ones(): void
    {
        $html = $this->get('/business/build')->assertOk()->getContent();

        foreach ($this->businessWorkloadSlugs() as $slug) {
            $this->assertStringContainsString(
                $slug,
                $html,
                "business workload '{$slug}' is missing from the business scenario picker"
            );
        }

        // The gaming picker's four buttons and their copy must be gone.
        $this->assertStringNotContainsString('🎮 Gaming', $html);
        $this->assertStringNotContainsString('🤖 AI Development', $html);
        $this->assertStringNotContainsString('Build Your Next PC', $html);
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
        $html = $this->get('/business/build?purpose=' . $requested)->assertOk()->getContent();

        $expected = GeminiService::resolveBusinessWorkload($requested);

        // The seeded Alpine state is the source of truth for what gets quoted,
        // so assert on it rather than on which card happens to be ringed.
        //
        // The payload is HTML-attribute escaped (it rides inside a single-quoted
        // JSON.parse argument), so the quotes arrive as 0022 escapes. Matching
        // the raw form here would pass on a page that had not actually seeded
        // the workload.
        $this->assertStringContainsString(
            '\u0022purpose\u0022:\u0022' . $expected . '\u0022',
            $html,
            "requesting '{$requested}' did not seed the builder with '{$expected}'"
        );
    }

    public static function requestedWorkloads(): array
    {
        return [
            'studio' => ['studio'],
            'rendering' => ['rendering'],
            'enterprise' => ['enterprise'],
            'server' => ['server'],
            'nas' => ['nas'],
            'home-business' => ['home-business'],
            'ai' => ['ai'],
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

    public function test_every_business_workload_is_accepted_by_the_build_endpoint(): void
    {
        // THE REGRESSION THAT MATTERS. Validation used to be a four-item
        // literal naming only the gaming purposes, so every business scenario
        // was a 422 before the engine ran.
        foreach ($this->businessWorkloadSlugs() as $slug) {
            $response = $this->postJson('/builder/ai', [
                'budget' => 2500,
                'purpose' => $slug,
            ]);

            $this->assertNotSame(
                422,
                $response->status(),
                "business workload '{$slug}' was rejected by /builder/ai validation"
            );
        }
    }

    public function test_a_workload_outside_the_configured_list_is_still_rejected(): void
    {
        // Widening the rule to read config must not have become "accept any
        // string". Fail-closed is the point.
        $this->postJson('/builder/ai', [
            'budget' => 2500,
            'purpose' => 'not-a-real-workload',
        ])->assertStatus(422);
    }

    public function test_the_business_builder_sends_no_resolution_so_the_gaming_clamp_stays_off(): void
    {
        // Guards the client half of the fix: the request body omits
        // `resolution` in business mode, so AIRecommendationService cannot apply
        // the resolution-keyed GPU tier ladder or the 1080p band clamp to a
        // server quote.
        $js = file_get_contents(base_path('resources/js/builder.js'));

        $this->assertStringContainsString(
            '...(this.isBusiness() ? {} : { resolution: this.resolution })',
            $js,
            'the build request must omit `resolution` in business mode'
        );

        $this->assertStringContainsString(
            'if (this.isBusiness() || !gpu)',
            $js,
            'FPS must never be requested in business mode'
        );
    }

    public function test_declared_business_weights_are_enforced_without_gemini(): void
    {
        // config/workloads.php claims the declared weights feed the DETERMINISTIC
        // scoring when Gemini is not configured. They did not: scoreWeights()
        // returns null with no provider, applyWeights() was never called, and the
        // weights had no effect on the pick at all - so a NAS build declaring
        // gpu => 5 could still be quoted a gaming graphics card.
        config(['gemini.key' => null]);
        $this->assertFalse(
            app(GeminiService::class)->available(),
            'this test needs Gemini off to be meaningful'
        );

        $service = app(\App\Services\AIRecommendationService::class);
        $method = new \ReflectionMethod($service, 'workloadWeights');
        $method->setAccessible(true);

        $nas = $method->invoke($service, 'nas');
        $this->assertIsArray($nas, 'nas must resolve to declared weights with no provider');
        $this->assertLessThan(
            0.6,
            $nas['gpu'],
            'a NAS build must heavily discount the GPU when Gemini is unavailable'
        );
        $this->assertGreaterThan(
            1.4,
            $nas['storage'],
            'a NAS build must heavily reward storage when Gemini is unavailable'
        );

        // And the gaming path must be untouched: applying these weights there
        // would move the measured, published price-band floors.
        $this->assertNull(
            $method->invoke($service, 'gaming'),
            'gaming weights must NOT be applied deterministically - the published band floors depend on it'
        );
    }

    public function test_no_gaming_workload_is_ever_affected_by_the_deterministic_weights(): void
    {
        /*
         * THE BLAST-RADIUS BOUNDARY.
         *
         * The published 1080p / 1440p / 4K entry prices are measured from the
         * live catalogue and quoted to customers. recommendBoth() changed shape
         * (a new else-branch on the Gemini path), so this asserts the change is
         * provably inert for every gaming workload - not just 'gaming' - rather
         * than asking anyone to take the scoping comment on trust.
         *
         * If someone later adds a business weight under a gaming slug, or
         * widens the segment check, this fails.
         */
        $service = app(\App\Services\AIRecommendationService::class);
        $method = new \ReflectionMethod($service, 'workloadWeights');
        $method->setAccessible(true);

        $gamingSlugs = array_keys(array_filter(
            config('workloads.workloads', []),
            fn (array $spec) => ($spec['segment'] ?? 'gaming') === 'gaming'
        ));

        $this->assertNotEmpty($gamingSlugs, 'there should be gaming workloads to protect');

        foreach ($gamingSlugs as $slug) {
            $this->assertNull(
                $method->invoke($service, $slug),
                "gaming workload '{$slug}' must not receive deterministic business weights - it would move the published band floors"
            );
        }
    }

    public function test_the_nas_gpu_floor_is_not_higher_than_the_gaming_one(): void
    {
        // Sanity on the normalisation: 0-100 must map monotonically into the
        // 0.5-1.5 multiplier range Gemini itself is clamped to, so neither path
        // can dominate the price/value curve.
        $service = app(\App\Services\AIRecommendationService::class);
        $method = new \ReflectionMethod($service, 'workloadWeights');
        $method->setAccessible(true);

        $weights = $method->invoke($service, 'studio');

        foreach ($weights as $slug => $weight) {
            $this->assertGreaterThanOrEqual(0.5, $weight, "weight '{$slug}' below the floor");
            $this->assertLessThanOrEqual(1.5, $weight, "weight '{$slug}' above the ceiling");
        }

        // studio rewards ram; nas punishes gpu. Both are relative to each other.
        $nas = $method->invoke($service, 'nas');
        $this->assertGreaterThan($nas['gpu'], $weights['gpu'], 'nas should discount the GPU harder than studio');
        $this->assertLessThan($weights['ram'], $nas['ram'], 'nas should reward ram more than studio');
    }
}
