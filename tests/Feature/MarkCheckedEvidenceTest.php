<?php
// Genie 2026-09-28: markChecked() must keep source_url intact (it is the
// refresh handle) while recording the merchant page in specs, and it must not
// clobber unrelated spec keys.
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Services\PriceIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkCheckedEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeComponent(array $overrides = []): Component
    {
        // No component/category factories exist in this project, and the test
        // database is the real schema - so create the rows directly.
        $category = Category::query()->create([
            'name' => 'Test Category '.uniqid(),
            'slug' => 'test-category-'.uniqid(),
        ]);

        // components.manufacturer_id is NOT NULL with no default.
        $manufacturer = new \App\Models\Manufacturer();
        $manufacturer->name = 'Test Manufacturer '.uniqid();
        $manufacturer->slug = 'test-manufacturer-'.uniqid();
        $manufacturer->save();

        $component = new Component();
        $component->category_id = $category->id;
        $component->manufacturer_id = $manufacturer->id;
        $component->name = $overrides['name'] ?? 'Test Part';
        $component->slug = 'test-part-'.uniqid();
        $component->price = $overrides['price'] ?? 10.0;
        $component->currency = 'GBP';
        $component->stock = $overrides['stock'] ?? 5;
        $component->active = 1;
        $component->source_url = $overrides['source_url'] ?? null;
        $component->specs = $overrides['specs'] ?? ['cores' => 8, 'threads' => 16];
        $component->price_checked_at = $overrides['price_checked_at'] ?? null;
        $component->save();

        return $component->fresh();
    }

    public function test_source_url_survives_and_evidence_lands_in_specs(): void
    {
        $component = $this->makeComponent(['source_url' => 'https://uk.pcpartpicker.com/product/abc/part']);

        app(PriceIntegrityService::class)->markChecked(
            $component,
            123.45,
            1,
            'https://www.scan.co.uk/products/part'
        );

        $fresh = $component->fresh();

        $this->assertSame(
            'https://uk.pcpartpicker.com/product/abc/part',
            $fresh->source_url,
            'source_url is the refresh handle and must never be overwritten by the merchant URL'
        );
        $this->assertEquals(123.45, $fresh->price);
        $this->assertEquals(1, $fresh->stock);
        $this->assertNotNull($fresh->price_checked_at);
        $this->assertSame('https://www.scan.co.uk/products/part', $fresh->specs['price_evidence_url']);
    }

    public function test_existing_specs_survive_the_evidence_write(): void
    {
        $component = $this->makeComponent([
            'source_url' => 'https://uk.pcpartpicker.com/product/abc/part',
            'specs' => ['cores' => 8, 'threads' => 16, 'type' => 'DDR5'],
        ]);

        app(PriceIntegrityService::class)->markChecked($component, 50.0, 1, 'https://www.overclockers.co.uk/p/x');

        $specs = $component->fresh()->specs;
        $this->assertSame(8, $specs['cores']);
        $this->assertSame(16, $specs['threads']);
        $this->assertSame('DDR5', $specs['type']);
        $this->assertSame('https://www.overclockers.co.uk/p/x', $specs['price_evidence_url']);
    }

    public function test_no_evidence_url_leaves_specs_and_url_untouched(): void
    {
        $component = $this->makeComponent([
            'source_url' => 'https://uk.pcpartpicker.com/product/abc/part',
            'specs' => ['cores' => 4],
        ]);

        app(PriceIntegrityService::class)->markChecked($component, 20.0, 0, null);

        $fresh = $component->fresh();
        $this->assertSame('https://uk.pcpartpicker.com/product/abc/part', $fresh->source_url);
        $this->assertArrayNotHasKey('price_evidence_url', $fresh->specs);
        $this->assertEquals(0, $fresh->stock);
    }

    public function test_a_stamped_row_is_fresh_and_survives_pool_cleaning(): void
    {
        $component = $this->makeComponent(['source_url' => 'https://uk.pcpartpicker.com/product/abc/part']);

        $integrity = app(PriceIntegrityService::class);
        $this->assertFalse($integrity->freshness($component)['fresh'], 'a never-checked row must not read as fresh');

        $integrity->markChecked($component, 99.0, 1, 'https://box.co.uk/p/y');

        $this->assertTrue($integrity->freshness($component->fresh())['fresh']);
        $this->assertTrue(
            $integrity->clean(collect([$component->fresh()]))->isNotEmpty(),
            'a freshly evidenced, sourced row must survive pool cleaning'
        );
    }

    /**
     * Live Neon, 2026-09-28, straight after the price_checked_at migration ran
     * for the first time: clean() returned 0 of 2,708 publishable and every
     * measured band floor collapsed to GBP 0.00, because no production row has
     * ever been verified against a merchant. If the freshness rejection were
     * unconditional, that state presents the customer with an empty configurator.
     *
     * So the freshness rejection is opt-in, and this pins both halves of that
     * contract: the shop keeps trading while evidence coverage is zero, and the
     * gate still does its job the moment it is switched on.
     */
    public function test_freshness_is_reported_even_while_withholding_is_switched_off(): void
    {
        config(['price_integrity.enforce_freshness' => false]);

        $component = $this->makeComponent(['source_url' => 'https://uk.pcpartpicker.com/product/abc/part']);

        $integrity = app(PriceIntegrityService::class);
        $this->assertFalse(PriceIntegrityService::enforcesFreshness());

        // The verdict is still computed and still says "no", so the gap is
        // visible to whoever runs the report.
        $this->assertFalse($integrity->freshness($component)['fresh']);

        // But an unverified, sourced row is not withheld from the build pool.
        $this->assertTrue(
            $integrity->clean(collect([$component]))->isNotEmpty(),
            'with withholding off, an unverified row must stay in the pool or the shop goes dark'
        );
    }

    public function test_freshness_withholding_applies_once_switched_on(): void
    {
        config(['price_integrity.enforce_freshness' => true]);

        $component = $this->makeComponent(['source_url' => 'https://uk.pcpartpicker.com/product/abc/part']);

        $integrity = app(PriceIntegrityService::class);
        $this->assertTrue(PriceIntegrityService::enforcesFreshness());

        $this->assertTrue(
            $integrity->clean(collect([$component]))->isEmpty(),
            'with withholding on, an unverified row must be refused'
        );

        $integrity->markChecked($component, 99.0, 1, 'https://box.co.uk/p/y');

        $this->assertTrue(
            $integrity->clean(collect([$component->fresh()]))->isNotEmpty(),
            'a row with live merchant evidence must be allowed back through'
        );
    }

    public function test_structural_rejects_apply_even_while_withholding_is_off(): void
    {
        config(['price_integrity.enforce_freshness' => false]);

        $unsourced = $this->makeComponent(['source_url' => '']);
        $integrity = app(PriceIntegrityService::class);

        $this->assertTrue(
            $integrity->clean(collect([$unsourced]))->isEmpty(),
            'a row with no source URL is unquotable on any setting'
        );
    }
}
