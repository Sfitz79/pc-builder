<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Models\Manufacturer;
use App\Services\AIRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Honest price bands and the below-minimum clamp.
 *
 * Boss directive 2026-09-28: advertise only bands we can genuinely deliver,
 * start the budget slider where a real build starts, and when a customer types
 * less than the minimum, move the input up to it and explain why in plain
 * English - offering a part-new/part-used build on WhatsApp rather than
 * leaving them at a dead end.
 *
 * These are money rules and an overclaim guard, so they are tested rather than
 * assumed. The catalogue is seeded small and deliberately (one card per tier
 * class) so each assertion states exactly which part of the ladder it is
 * proving; the real figures are re-measured against the live catalogue by
 * scripts/regression-probe.php and scripts/genie-measure-entry-price.php.
 */
class BudgetBandsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A deliberately minimal but COMPLETE and physically legal catalogue:
     * one AM4 processor, one AM4 board, DDR4, one drive, one shroud case, an
     * air cooler, and one graphics card in each tier class.
     */
    private function seedCatalogue(): void
    {
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
            $cats[$slug] = Category::create(['name' => $name, 'slug' => $slug]);
        }

        // Created BEFORE the $part closure below, because `use` captures by
        // value at closure-creation time.
        $maker = Manufacturer::create(['name' => 'PCTechGuy Test', 'slug' => 'pctechguy-test']);

        $part = function (string $slug, string $name, float $price, array $specs = [], array $extra = []) use ($cats, $maker) {
            return Component::create(array_merge([
                'category_id' => $cats[$slug]->id,
                'manufacturer_id' => $maker->id,
                'name' => $name,
                'slug' => strtolower(str_replace([' ', '/'], '-', $name)),
                'price' => $price,
                'currency' => 'GBP',
                'stock' => 25,
                'active' => true,
                'specs' => $specs,
                // PriceIntegrityService::clean() drops any row with no
                // source_url or a stale price_checked_at, because an
                // unevidenced price costs us the whole order. Seeded parts must
                // therefore arrive evidenced, exactly as live catalogue rows do.
                'source_url' => 'https://example.com/'.strtolower(str_replace([' ', '/'], '-', $name)),
                'price_checked_at' => now(),
            ], $extra));
        };

        // Six-core AM4 entry processor, no integrated graphics - the part the
        // boss named as our entry chip. This is the cheapest legal CPU, so it
        // is the one the 1080p band must resolve to.
        $part('cpu', 'AMD Ryzen 5 4500', 59.99, ['cores' => 6, 'threads' => 6], ['socket' => 'AM4']);

        // Eight-core AM4 chip. GPU_CPU_COHERENCE refuses to pair a sub-8-core
        // CPU with a tier-5 card, so without this the 4K band could not be
        // measured at all. Deliberately dearer than the 4500, which is what
        // forces the 4K band to price higher than the 1080p one.
        $part('cpu', 'AMD Ryzen 7 2700X', 219.99, ['cores' => 8, 'threads' => 8], ['socket' => 'AM4']);

        $part('motherboard', 'ASRock A520M-HVS', 43.37, ['memory_type' => 'DDR4'], ['socket' => 'AM4']);
        // `capacity` is the field specCapacityGb()/ramCapacityGb() read. Note
        // viablePool('ram') reads it directly while passesSpecFloor() falls back
        // to the product name, so a kit must carry it to be quotable at all.
        $part('ram', 'Crucial Pro 32 GB DDR4 3200', 215.94, ['capacity' => '32GB', 'speed' => 3200, 'type' => 'DDR4']);

        // ---------------------------------------------------------------------
        // The 4K PLATFORM: LGA1851 + DDR5.
        //
        // Why these three rows exist. GPU_CPU_COHERENCE[5] is not a core-count
        // rule alone - it is `min_cores => 8, modern_platform => true`, and
        // MODERN_PLATFORM_SOCKETS is ['AM5', 'LGA1851']. The AM4-only fixture
        // above therefore cannot express a 4K machine AT ALL: the 8-core Ryzen
        // 7 2700X clears the core count and is then refused by
        // isEntryPlatformCpu(), so cpuCanFeedGpu() is false for every card in
        // the pool, cheapestCompleteDgpuBuild() finds no coherent pair, and
        // entryPriceFor('4K') returns null. That made workableBands() fall back
        // to the hand-typed GBP 2,290 floor with measured => false, which is
        // precisely the "typed guess advertised as a measured price" the two
        // failing tests exist to forbid.
        //
        // This is a FIXTURE gap, not a code defect. The policy is correct and
        // deliberate (a tier-5 card behind an EOL AM4 board is the exact
        // mismatch the coherence rule exists to stop), and the real catalogue
        // is not short of modern parts - measured against the UK snapshot in
        // database/scraped/: 49 priced AM5/LGA1851 CPUs, 330 AM5 boards and 744
        // dual-channel DDR5 kits. The fixture simply failed to include one.
        //
        // Every row below is a REAL UK catalogue row, price and spec included,
        // from database/scraped/ (pcpartpicker, scraped 2026-07-31):
        //   Intel Core Ultra 5 225F  GBP 138.97  LGA1851, 10 cores, no iGPU
        //   ASRock H810M-H            GBP  71.99  LGA1851, mATX, 2 DIMM slots
        //   Crucial CT2K16G56C46S5    GBP 284.00  DDR5-5600, 2 x 16GB
        // The 225F is deliberately the CHEAPEST compliant modern processor in
        // that snapshot (GBP 138.97 vs the Ryzen 7 8700F at GBP 192.05), because
        // entryPriceFor() must measure the cheapest legal build, not a convenient
        // one. LGA1851 rather than AM5 because the 225F is genuinely cheaper than
        // any 8-core AM5 part without integrated graphics.
        //
        // These rows deliberately do NOT disturb the 1080p or 1440p floors: both
        // bands are tier 2 and tier 3, and GPU_CPU_COHERENCE only constrains
        // tiers 4 and 5, so cpuCanFeedGpu() places no platform constraint there
        // and the GBP 59.99 Ryzen 5 4500 still wins the cheapest-CPU race. Both
        // floors still measure at GBP 1,368.46 and GBP 1,522.35 with these rows
        // present.
        // ---------------------------------------------------------------------
        $part('cpu', 'Intel Core Ultra 5 225F', 138.97, ['cores' => 10, 'threads' => 10, 'tdp' => 65], ['socket' => 'LGA1851']);
        $part('motherboard', 'ASRock H810M-H', 71.99, ['socketCPU' => 'LGA1851', 'memorySlots' => 2], ['socket' => 'LGA1851', 'memory_type' => 'DDR5']);
        $part('ram', 'Crucial Pro 32 GB DDR5 5600', 284.00, ['capacity' => '32GB', 'speed' => 'DDR5-5600', 'type' => 'DDR5', 'modules' => '2 x 16GB'], ['memory_type' => 'DDR5']);
        $part('storage', 'Silicon Power Ace A5X 1TB', 57.99, ['capacity' => '1TB', 'type' => 'NVMe']);
        $part('case', 'Thermaltake View 170 ARGB', 50.47, ['psu_shroud' => true]);
        $part('cooler', 'ID-COOLING FROSTFLOW X', 44.99, ['type' => 'Air', 'tdp' => 150]);
        $part('psu', 'Gigabyte P850GM', 63.94, ['wattage' => 850]);
        $part('psu', 'Gigabyte UD1000GM PG5 V2', 89.99, ['wattage' => 1000]);

        // Tier 1 - 1080p medium settings, NOT AAA-high. Must never satisfy the
        // 1080p band.
        $part('gpu', 'Sparkle ELF Arc A380', 159.49, ['memory' => '6GB'], ['chipset' => 'Arc A380']);

        // Tier 2 - the 1080p AAA-high floor.
        $part('gpu', 'ASRock Challenger SE OC Arc A750', 240.72, ['memory' => '8GB'], ['chipset' => 'Arc A750']);

        // Tier 3 - the 1440p AAA-high floor.
        $part('gpu', 'Sapphire PULSE Radeon RX 9060 XT', 389.99, ['memory' => '8GB'], ['chipset' => 'Radeon RX 9060 XT']);

        // Tier 5 - the 4K AAA-high floor.
        $part('gpu', 'Gigabyte GAMING OC Radeon RX 9070 GRE', 503.94, ['memory' => '12GB'], ['chipset' => 'Radeon RX 9070 GRE']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCatalogue();
    }

    /**
     * @return array<string, array>
     */
    private function bands(): array
    {
        $response = $this->getJson('/builder/bands');

        $response->assertOk();

        return $response->json('bands');
    }

    public function test_bands_endpoint_publishes_every_resolution(): void
    {
        $bands = $this->bands();

        $this->assertArrayHasKey('1080p', $bands);
        $this->assertArrayHasKey('1440p', $bands);
        $this->assertArrayHasKey('4k', $bands);
    }

    public function test_every_band_is_measured_and_not_inverted(): void
    {
        foreach ($this->bands() as $key => $band) {
            $this->assertTrue(
                $band['measured'],
                "The {$key} band floor is a typed guess, not a measured price."
            );

            $this->assertGreaterThan(0, $band['min'], "The {$key} band has no floor.");

            $this->assertGreaterThan(
                $band['min'],
                $band['max'],
                "The {$key} band is inverted: floor above ceiling."
            );
        }
    }

    /**
     * Bands are a ladder, not a set of overlapping ranges.
     *
     * Until 2026-09-28 the table read 1080p 1350-1500, 1440p 1530-2500, 4K
     * 1960-3500. The 1440p ceiling (2500) sat GBP 540 ABOVE the 4K floor
     * (1960), so a GBP 2,000 budget was simultaneously a valid 1440p and a
     * valid 4K ask. Which resolution the customer was then promised depended on
     * iteration order rather than on anything we told them.
     *
     * This is asserted against the published constants rather than the measured
     * values, because the measured floors move with whatever catalogue is loaded
     * - they differ between local SQLite and live Neon. The published table is
     * the promise we actually make, so that is the thing that must be a ladder.
     */
    public function test_published_bands_are_a_contiguous_ladder(): void
    {
        $published = AIRecommendationService::RESOLUTION_BANDS;
        $keys = ['1080p', '1440p', '4k'];

        for ($i = 0; $i < count($keys) - 1; $i++) {
            $lower = $published[$keys[$i]];
            $upper = $published[$keys[$i + 1]];

            $this->assertLessThanOrEqual(
                $upper['min'],
                $lower['max'],
                sprintf(
                    'The %s band (to GBP %s) overlaps the %s band (from GBP %s). '
                    .'A budget in that range belongs to two resolution promises at once.',
                    $keys[$i],
                    number_format($lower['max'], 0),
                    $keys[$i + 1],
                    number_format($upper['min'], 0)
                )
            );
        }
    }

    /**
     * "1080p from GBP X" has to mean a build exists at GBP X.
     *
     * The published floors were originally measured against the local SQLite
     * catalogue while the storefront served the Neon one, which made every floor
     * an under-promise: 1080p was advertised from GBP 1,350 when the cheapest
     * machine the engine can quote from the real catalogue is GBP 1,430.
     *
     * This pins the numbers that came back from live Neon on 2026-09-28
     * (2,708 components, PostgreSQL 18.6) rather than re-deriving them from
     * whatever fixture is loaded, so a real price move has to be a deliberate
     * edit to this test with a new measurement behind it - not a silent drift.
     */
    public function test_published_floors_match_the_live_neon_measurement(): void
    {
        $measuredOnProduction = [
            '1080p' => 1430.00,
            '1440p' => 1640.00,
            '4k' => 2290.00,
        ];

        foreach ($measuredOnProduction as $key => $measured) {
            $published = (float) AIRecommendationService::RESOLUTION_BANDS[$key]['min'];

            $this->assertEqualsWithDelta(
                $measured,
                $published,
                0.01,
                sprintf(
                    'The %s floor is GBP %s but the live Neon catalogue measured GBP %s on 2026-09-28. '
                    .'If the catalogue has genuinely moved, re-measure against Neon and update both '
                    .'this test and RESOLUTION_BANDS together.',
                    $key,
                    number_format($published, 2),
                    number_format($measured, 2)
                )
            );
        }
    }

    public function test_a_band_floor_clears_the_cheapest_real_build(): void
    {
        $service = app(AIRecommendationService::class);
        $bands = $this->bands();

        foreach (['1080p' => '1080P', '1440p' => '1440P', '4k' => '4K'] as $key => $resolution) {
            $measured = $service->entryPriceFor($resolution);

            $this->assertNotNull($measured, "No complete build could be measured for {$resolution}.");
            $this->assertTrue($measured['complete']);
            $this->assertGreaterThanOrEqual(
                $measured['price'],
                $bands[$key]['min'],
                "The {$key} floor is below a build we can actually assemble."
            );
        }
    }

    /**
     * The point of the ladder: every band promises AAA at high settings, and
     * each step up promises at least as much as the one below it.
     */
    public function test_every_band_promises_aaa_at_high_settings(): void
    {
        $bands = $this->bands();

        foreach ($bands as $key => $band) {
            $this->assertGreaterThanOrEqual(
                2,
                $band['floor_gpu_tier'],
                "The {$key} band is set below the AAA-high bar."
            );
        }

        $this->assertGreaterThanOrEqual($bands['1080p']['floor_gpu_tier'], $bands['1440p']['floor_gpu_tier']);
        $this->assertGreaterThanOrEqual($bands['1440p']['floor_gpu_tier'], $bands['4k']['floor_gpu_tier']);
    }

    /**
     * The cheapest possible card in the catalogue is a tier-1 Arc A380. It must
     * not be allowed to satisfy any published band, or the "AAA at high
     * settings" claim is a lie.
     */
    public function test_an_entry_level_card_never_satisfies_a_band(): void
    {
        $service = app(AIRecommendationService::class);

        $a380 = Component::where('chipset', 'Arc A380')->firstOrFail();

        foreach (['1080P', '1440P', '4K'] as $resolution) {
            $this->assertFalse(
                $service->gpuTierMeetsResolution($a380, $resolution),
                "A tier-1 card was accepted for {$resolution}."
            );
        }
    }

    public function test_bands_endpoint_exposes_the_whatsapp_number(): void
    {
        $this->getJson('/builder/bands')
            ->assertOk()
            ->assertJsonPath('whatsapp', AIRecommendationService::WHATSAPP_NUMBER);
    }

    public function test_a_budget_below_the_minimum_is_raised_and_explained(): void
    {
        $floor = $this->bands()['1080p']['min'];

        $response = $this->postJson('/builder/ai', [
            'budget' => 700,
            'purpose' => 'gaming',
            'resolution' => '1080P',
        ]);

        $response->assertOk();

        $this->assertEquals(700.0, $response->json('budget_asked'));
        $this->assertEquals((float) $floor, $response->json('budget_used'));
        $this->assertTrue($response->json('notice.raised'));
        $this->assertEquals((float) $floor, $response->json('notice.min'));

        // The reply must say which band it was judged against, or the
        // explanation can contradict the machine that came back.
        $this->assertSame('1080P', $response->json('resolution'));

        $message = (string) $response->json('notice.message');

        $this->assertStringContainsString('£', $message, 'A customer-facing price needs its currency symbol.');
        $this->assertStringNotContainsStringIgnoringCase('spec floor', $message, 'The explanation must be plain English.');
        $this->assertStringNotContainsStringIgnoringCase('null', $message);
    }

    public function test_a_too_low_budget_offers_the_part_new_part_used_route(): void
    {
        $response = $this->postJson('/builder/ai', [
            'budget' => 900,
            'purpose' => 'gaming',
            'resolution' => '1440P',
        ]);

        $response->assertOk();

        $this->assertTrue($response->json('notice.hybrid.available'));
        $this->assertSame(
            AIRecommendationService::WHATSAPP_NUMBER,
            $response->json('notice.hybrid.whatsapp')
        );
        $this->assertStringContainsString('wa.me/', (string) $response->json('notice.hybrid.whatsapp_url'));
        $this->assertNotSame('', (string) $response->json('notice.hybrid.message'));
    }

    public function test_a_budget_inside_the_band_is_left_alone(): void
    {
        $band = $this->bands()['1080p'];
        $budget = (int) round(($band['min'] + $band['max']) / 2);

        $response = $this->postJson('/builder/ai', [
            'budget' => $budget,
            'purpose' => 'gaming',
            'resolution' => '1080P',
        ]);

        $response->assertOk();

        $this->assertEquals((float) $budget, $response->json('budget_used'));
        $this->assertFalse($response->json('notice.raised'));
        $this->assertSame('', $response->json('notice.message'));
    }

    /**
     * The clamp must not simply hand back a bigger machine: the machine it
     * returns still has to be a real, complete build that clears the band's own
     * quality promise.
     */
    public function test_the_machine_returned_for_a_clamped_budget_meets_the_band_promise(): void
    {
        $response = $this->postJson('/builder/ai', [
            'budget' => 1500,
            'purpose' => 'gaming',
            'resolution' => '4K',
        ]);

        $response->assertOk();

        $this->assertTrue($response->json('budget.complete'));
        $this->assertNotNull($response->json('budget.components.gpu'));
        $this->assertNotNull($response->json('budget.components.cpu'));
        $this->assertGreaterThan(0, (float) $response->json('budget.total'));

        // And it must not be the tier-1 card, because that is the whole point.
        $gpu = $response->json('budget.components.gpu');
        $this->assertStringNotContainsStringIgnoringCase('A380', (string) $gpu['name']);
    }
}
