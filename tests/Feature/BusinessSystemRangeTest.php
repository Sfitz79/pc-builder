<?php

namespace Tests\Feature;

use App\Services\BusinessCapabilityGate;
use App\Services\BusinessSystemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SME range: business size -> job type -> system type.
 *
 * A sole trader and a hospital imaging department were both previously offered
 * "Creative Studio" as though they wanted the same machine at the same price.
 * Businesses do not buy by software workload; they buy by what they do all day
 * and how many people are doing it. These tests pin that shape down.
 *
 * The behaviour they protect is mostly NEGATIVE - a system type we cannot build
 * must not offer a build button - and negative promises are exactly the kind
 * that quietly stop being true, because nothing breaks when they lapse.
 */
class BusinessSystemRangeTest extends TestCase
{
    use RefreshDatabase;

    private function seedBusinessAirCooler(): void
    {
        // quiet_cooling reads the product name, so the fixture has to carry a
        // recognised air-cooler family or every system type is unbuildable and
        // these tests pass by accident.
        $maker = \App\Models\Manufacturer::create(['name' => 'Test', 'slug' => 'test-mfr']);
        $category = \App\Models\Category::firstOrCreate(['slug' => 'cooler'], ['name' => 'Coolers']);

        \App\Models\Component::create([
            'category_id' => $category->id,
            'manufacturer_id' => $maker->id,
            'name' => 'Noctua NH-D15 chromax.black',
            'slug' => 'noctua-nh-d15',
            'price' => 109.95,
            'currency' => 'GBP',
            'stock' => 10,
            'active' => true,
            'segments' => ['gaming', 'business'],
            'specs' => [],
            'source_url' => 'https://example.com/nh-d15',
            'price_checked_at' => now(),
        ]);
    }

    public function test_the_range_covers_every_size_a_small_business_actually_is(): void
    {
        $groups = app(BusinessSystemType::class)->bySize();

        // The six sizes are the range the business is addressed to. If one is
        // missing, a real customer has nowhere to go, and nothing else fails.
        foreach ([
            'home-office', 'small-office', 'retail',
            'workstation', 'server', 'storage',
        ] as $size) {
            $this->assertArrayHasKey(
                $size,
                $groups,
                "business size '{$size}' is missing from the range"
            );

            $this->assertNotEmpty(
                $groups[$size]['systems'],
                "business size '{$size}' has no system types under it"
            );
        }
    }

    public function test_every_system_type_points_at_a_workload_that_exists(): void
    {
        $known = array_keys(\App\Services\GeminiService::businessWorkloads());

        foreach (\App\Services\BusinessSystemType::class ? app(BusinessSystemType::class)->all() : [] as $system) {
            $this->assertArrayHasKey(
                'workload',
                $system,
                "system type '{$system['slug']}' does not declare a workload, so the picker cannot use it"
            );

            $this->assertContains(
                $system['workload'],
                $known,
                "system type '{$system['slug']}' points at workload '{$system['workload']}', which is not a business workload"
            );
        }
    }

    public function test_every_system_type_declares_a_price_band_and_a_memory_floor(): void
    {
        foreach (app(BusinessSystemType::class)->all() as $system) {
            $band = (array) ($system['band'] ?? []);

            $this->assertCount(2, $band, "system type '{$system['slug']}' has no two-sided price band");
            $this->assertGreaterThan(0, $band[0], "system type '{$system['slug']}' has a zero band floor");
            $this->assertGreaterThan($band[0], $band[1], "system type '{$system['slug']}' has an inverted price band");

            $this->assertArrayHasKey(
                'minRamGb',
                $system,
                "system type '{$system['slug']}' does not say how much memory the job needs"
            );
        }
    }

    public function test_a_system_type_we_cannot_stock_offers_no_build_button(): void
    {
        $this->seedBusinessAirCooler();

        $html = $this->get('/business/build')->assertOk()->getContent();

        foreach (app(BusinessSystemType::class)->all() as $system) {
            $detail = app(BusinessSystemType::class)->find($system['slug']);
            $buildLink = route('business.build', ['type' => $system['slug']]);

            if ($detail['buildable']) {
                $this->assertStringContainsString(
                    $system['slug'],
                    $html,
                    "buildable system type '{$system['slug']}' is missing from the range"
                );

                continue;
            }

            // The promise these tests exist to protect: a product we cannot
            // assemble never gets a one-click configure link.
            $this->assertStringNotContainsString(
                '&type='.$system['slug'].'"',
                $html,
                "'{$system['slug']}' cannot be built but still offers a Configure this link"
            );

            $this->assertNotSame(
                '',
                $detail['unavailable_reason'],
                "'{$system['slug']}' is unservable and must say why, not just drop the button"
            );
        }
    }

    public function test_an_unavailable_reason_names_the_actual_missing_part(): void
    {
        $this->seedBusinessAirCooler();

        // The NAS system type cannot be built because there are no enterprise
        // drives, no multi-bay case and no ECC memory. The message has to say so
        // - "not available" tells the customer nothing and tells us nothing.
        $nas = app(BusinessSystemType::class)->find('storage-shared');

        $this->assertFalse($nas['buildable']);
        $this->assertNotEmpty($nas['missing_labels']);
        $this->assertStringContainsStringIgnoringCase('drive', implode(' ', $nas['missing_labels']));
        $this->assertStringContainsStringIgnoringCase('bay', implode(' ', $nas['missing_labels']));
    }

    public function test_the_two_reasons_a_system_is_unavailable_are_reported_separately(): void
    {
        $this->seedBusinessAirCooler();

        // storage-shared: missing parts ONLY. Its band starts at GBP 1,200,
        // which is ABOVE the measured entry build, so the band is fine and only
        // the parts are the problem.
        $shared = app(BusinessSystemType::class)->find('storage-shared');
        $this->assertTrue($shared['part_blocked'], 'storage-shared cannot be built: we hold no enterprise drives');
        $this->assertFalse(
            $shared['band_unreachable'],
            'a GBP 1,200 band floor is above the entry build, so it must NOT be flagged as unreachable'
        );

        // storage-backup: BOTH. It starts at GBP 800, below the measured entry
        // build, and it needs parts we do not hold.
        //
        // Two different problems with two different fixes - sourcing drives
        // versus repricing the range. Collapsing them into one "unavailable"
        // hides the second from whoever would fix it.
        $backup = app(BusinessSystemType::class)->find('storage-backup');
        $this->assertTrue($backup['part_blocked']);
        $this->assertTrue(
            $backup['band_unreachable'],
            'a GBP 800 band floor against a measured entry build must be flagged'
        );

        $this->assertStringContainsStringIgnoringCase(
            'we can actually build',
            $backup['unavailable_reason'],
            'when both apply, the message should say the band starts below what we build'
        );
    }

    public function test_a_band_that_starts_below_the_measured_entry_build_is_flagged(): void
    {
        $this->seedBusinessAirCooler();

        // home-office-standard claims GBP 700. Our cheapest genuinely buildable
        // machine is measured from the catalogue at GBP 1,060, so advertising a
        // GBP 700 starting point is a promise we cannot keep - the same class of
        // error as quoting a NAS we cannot assemble, only cheaper.
        $home = app(BusinessSystemType::class)->find('home-office-standard');

        $this->assertTrue(
            $home['band_unreachable'],
            'a GBP 700 band floor against a measured GBP 1,060 entry build must be flagged'
        );

        $this->assertGreaterThan(0, $home['builder_entry_price']);
        $this->assertLessThan($home['builder_entry_price'], $home['band_floor']);
    }

    public function test_the_entry_price_is_measured_not_typed(): void
    {
        // A hand-typed floor here would make the band check a constant dressed
        // up as a measurement.
        $home = app(BusinessSystemType::class)->find('home-office-standard');

        $measured = app(\App\Services\AIRecommendationService::class)->workableBands();
        $lowest = min(array_map(fn ($b) => (float) $b['min'], $measured));

        $this->assertEqualsWithDelta(
            $lowest,
            $home['builder_entry_price'],
            0.01,
            'the band check is comparing against a number that is not the measured entry price'
        );
    }

    public function test_a_workstation_job_needs_more_than_an_entry_professional_card(): void
    {
        $this->seedBusinessAirCooler();

        $maker = \App\Models\Manufacturer::first();
        $gpu = \App\Models\Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'Graphics']);

        // One entry-level professional card - which is exactly what the
        // catalogue holds. It satisfies `pro_gpu` and must NOT satisfy
        // `pro_gpu_high`.
        \App\Models\Component::create([
            'category_id' => $gpu->id,
            'manufacturer_id' => $maker->id,
            'name' => 'AMD Radeon Pro WX 5100',
            'slug' => 'radeon-pro-wx-5100',
            'price' => 220.90,
            'currency' => 'GBP',
            'stock' => 5,
            'active' => true,
            'segments' => ['business'],
            'specs' => ['memory' => 8],
            'source_url' => 'https://example.com/wx5100',
            'price_checked_at' => now(),
        ]);

        $gate = app(BusinessCapabilityGate::class);
        $gate->flush();

        $this->assertTrue($gate->has('pro_gpu'), 'an 8GB professional card is still a professional card');
        $this->assertFalse(
            $gate->has('pro_gpu_high'),
            'an 8GB entry card must not satisfy a 16GB+ professional requirement'
        );

        // And therefore a GBP 3,000-8,000 render node is not offered on it.
        $render = app(BusinessSystemType::class)->find('workstation-render');
        $this->assertFalse($render['buildable']);

        // CAD is a weaker requirement - any certified pro driver - so it still
        // qualifies, and pretending otherwise would hide a product we can serve.
        $cad = app(BusinessSystemType::class)->find('workstation-engineering');
        $this->assertTrue($cad['buildable']);
    }

    public function test_console_boards_are_not_mistaken_for_workstation_chipsets(): void
    {
        $this->seedBusinessAirCooler();

        $maker = \App\Models\Manufacturer::first();
        $mobo = \App\Models\Category::firstOrCreate(['slug' => 'motherboard'], ['name' => 'Motherboards']);

        // X870E is an enthusiast board, not a workstation chipset. The first
        // version of this check matched it and reported the capability as held,
        // which made the virtualisation host look one part closer to servable
        // than it is.
        \App\Models\Component::create([
            'category_id' => $mobo->id,
            'manufacturer_id' => $maker->id,
            'name' => 'Gigabyte X870E AORUS ELITE WIFI7',
            'slug' => 'x870e-aorus-elite',
            'price' => 259.99,
            'currency' => 'GBP',
            'stock' => 5,
            'active' => true,
            'specs' => ['chipset' => 'X870E'],
            'source_url' => 'https://example.com/x870e',
            'price_checked_at' => now(),
        ]);

        app(BusinessCapabilityGate::class)->flush();

        $this->assertFalse(
            app(BusinessCapabilityGate::class)->has('workstation_chipset'),
            'an X870E enthusiast board was accepted as a workstation chipset'
        );
    }

    public function test_the_range_is_rendered_on_the_business_builder(): void
    {
        $this->seedBusinessAirCooler();

        $html = $this->get('/business/build')->assertOk()->getContent();

        foreach (config('business.sizes') as $key => $meta) {
            // Escaped, because the label reaches the page through Blade and
            // "Retail & Front of House" is written as "&amp;" in the HTML. A
            // raw comparison here would fail on an ampersand and nowhere else.
            $this->assertStringContainsString(
                e($meta['label']),
                $html,
                "business size '{$key}' is not shown on the business builder"
            );
        }
    }
}
