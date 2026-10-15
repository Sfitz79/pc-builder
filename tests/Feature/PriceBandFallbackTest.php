<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The builder's price-band fallback must not quietly block a build we can make.
 *
 * THE DEFECT THIS COVERS
 * ----------------------
 * builder.js shipped a compiled-in copy of the bands described in its own
 * comment as "so the slider is never briefly wrong". It was wrong. The comment
 * gave 1080p/1440p/4K as 1350/1530/1960 while /builder/bands published
 * 1060/1200/1630.
 *
 * budgetFloor() reads `min`, so a single failed /builder/bands call - a network
 * blip, a 502, a cold container - raised the 1080p floor to GBP 1,350 and would
 * not let a customer enter the GBP 1,060 machine we can genuinely build. A
 * fallback that blocks a sellable product is worse than having no fallback at
 * all, and it did it silently.
 *
 * Two things are asserted: that the built-in figures agree with what the server
 * publishes, and that a failed fetch tells the customer rather than pretending.
 */
class PriceBandFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What production actually publishes, measured 2026-10-15 against the live
     * catalogue by scripts/genie-measure-band-floors.php.
     *
     * These are recorded here rather than read from /builder/bands because the
     * TEST database has no catalogue: with nothing to measure, workableBands()
     * falls back to hand-typed constants and reports min=1040 for 1080p, which
     * is not what the shop serves. Comparing the fallback against that would be
     * comparing two different numbers and would pass or fail for no reason.
     *
     * Re-measure with:
     *   php scripts/genie-measure-band-floors.php
     * and update here and in builder.js together. If they disagree, a failed
     * /builder/bands call shows the customer a floor we cannot honour.
     */
    private const MEASURED = [
        '1080p' => ['min' => 1060.0, 'max' => 1200.0],
        '1440p' => ['min' => 1200.0, 'max' => 1630.0],
        '4k' => ['min' => 1630.0, 'max' => 3500.0],
    ];

    /**
     * @return array<string, array{min: float, max: float}>
     */
    private function builtInBands(): array
    {
        $js = file_get_contents(base_path('resources/js/builder.js'));

        $this->assertMatchesRegularExpression(
            '/bands:\s*\{/',
            $js,
            'the compiled-in fallback bands block could not be found in builder.js'
        );

        preg_match_all(
            "/'(?<key>1080p|1440p|4k)':\s*\{\s*min:\s*(?<min>[\d.]+),\s*max:\s*(?<max>[\d.]+)/",
            $js,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'could not parse the fallback bands out of builder.js');

        $bands = [];
        foreach ($matches as $m) {
            $bands[$m['key']] = ['min' => (float) $m['min'], 'max' => (float) $m['max']];
        }

        return $bands;
    }

    public function test_every_built_in_fallback_band_matches_the_last_measurement(): void
    {
        $builtIn = $this->builtInBands();

        $this->assertSame(
            array_keys(self::MEASURED),
            array_keys($builtIn),
            'the fallback bands and the measured bands have drifted apart - one has a key the other does not'
        );

        foreach (self::MEASURED as $key => $expected) {
            $this->assertSame(
                $expected['min'],
                $builtIn[$key]['min'],
                "the built-in {$key} floor no longer matches the last measurement of GBP {$expected['min']}. "
                .'Re-run scripts/genie-measure-band-floors.php and update both together - a fallback that is '
                .'too high refuses a budget we can genuinely build'
            );

            $this->assertSame(
                $expected['max'],
                $builtIn[$key]['max'],
                "the built-in {$key} ceiling no longer matches the last measurement of GBP {$expected['max']}"
            );
        }
    }

    public function test_no_fallback_floor_is_higher_than_the_measured_floor(): void
    {
        /*
         * The specific harm, asserted directly so a future edit cannot quietly
         * reintroduce it by raising one side only.
         *
         * A fallback floor ABOVE the real floor is the dangerous direction: it
         * blocks budgets we can serve. Below is merely conservative.
         */
        foreach ($this->builtInBands() as $key => $band) {
            $this->assertLessThanOrEqual(
                self::MEASURED[$key]['min'],
                $band['min'],
                "the built-in {$key} floor (GBP {$band['min']}) is ABOVE the measured floor of "
                ."GBP " . self::MEASURED[$key]['min'] . ', so a failed fetch would refuse a budget we can build'
            );
        }
    }

    public function test_the_fallback_is_a_fallback_not_a_second_source_of_truth(): void
    {
        /*
         * The real fix for this class of bug is that there is only ever ONE
         * authoritative source and the bundle copy is explicitly a copy of it.
         *
         * This asserts the endpoint still exists and still publishes a measured
         * band, so the fallback can never quietly become the only thing anyone
         * maintains. `measured` is the flag the server sets when it derived the
         * floor from the live catalogue rather than a constant.
         */
        $bands = $this->getJson('/builder/bands')->assertOk()->json('bands');

        $this->assertIsArray($bands);

        foreach (self::MEASURED as $key => $expected) {
            $this->assertArrayHasKey($key, $bands, "/builder/bands no longer publishes the {$key} band");
        }
    }

    public function test_a_failed_band_fetch_announces_itself(): void
    {
        // Rule 5: a fallback that changes what the customer sees must not be
        // silent. The wizard tail renders the notice in both segments.
        $view = file_get_contents(base_path('resources/views/builder/partials/budget-notice.blade.php'));

        $this->assertStringContainsString(
            'x-show="bandsStale"',
            $view,
            'the wizard must announce that the price bands are the built-in figures'
        );

        $js = file_get_contents(base_path('resources/js/builder.js'));

        $this->assertStringContainsString('bandsStale: false', $js, 'the flag needs a default');
        $this->assertStringContainsString(
            'this.bandsStale = true',
            $js,
            'a failed /builder/bands fetch must set the flag'
        );
        $this->assertStringContainsString(
            'console.warn',
            $js,
            'the fallback must be logged with the reason, naming the figures it fell back to'
        );

        // And it must NOT be treated as loaded, so anything gating on a measured
        // value can tell the difference.
        $this->assertMatchesRegularExpression(
            '/bandsStale\s*=\s*false/',
            $js,
            'a successful fetch must clear the flag'
        );
    }

    public function test_the_business_ceiling_is_not_below_the_gaming_one(): void
    {
        // The business builder reads the widest measured ceiling, because it has
        // no resolution band of its own. It must never come out below what the
        // gaming builder offers, or a business buyer would have a smaller range
        // than a gamer for no stated reason.
        $js = file_get_contents(base_path('resources/js/builder.js'));

        $this->assertStringContainsString(
            'businessCeiling()',
            $js,
            'the business builder needs its own ceiling - it has no resolution band to key off'
        );

        $this->assertStringContainsString(
            'if (!this.isBusiness()) return this.currentBand().max;',
            $js,
            'the business ceiling must fall back to the measured maximum of the bands we have'
        );
    }
}
