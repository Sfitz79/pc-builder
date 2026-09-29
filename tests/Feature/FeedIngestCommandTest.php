<?php
// Genie 2026-09-29: the ingest command is the only path from a merchant feed to
// a stamped price, and it touches money, so its safety rails are tested:
// default is a DRY RUN, writes need --write, and only trusted matches at or
// above the chosen confidence may be written.
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Models\Manufacturer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedIngestCommandTest extends TestCase
{
    use RefreshDatabase;

    private function feedCsv(): string
    {
        return <<<'CSV'
product_name,search_price,in_stock,ean,mpn,merchant_deep_link,merchant_name
"Corsair Vengeance RGB 32GB Kit",379.99,1,0794025011271,CMW32GX2M2C3000K,https://www.scan.co.uk/products/ram,Scan
AMD Ryzen 7 5800X,319.99,0,,,https://www.overclockers.co.uk/p/cpu,Overclockers UK
Fractal Define R5,199.00,1,,,https://www.corsair.com/p/case,Corsair
CSV;
    }

    private function makeComponent(array $overrides = []): Component
    {
        $category = Category::query()->create([
            'name' => $overrides['category_name'] ?? 'Memory '.uniqid(),
            'slug' => 'cat-'.uniqid(),
        ]);

        $manufacturer = new Manufacturer();
        $manufacturer->name = 'Mfr '.uniqid();
        $manufacturer->slug = 'mfr-'.uniqid();
        $manufacturer->save();

        $component = new Component();
        $component->category_id = $category->id;
        $component->manufacturer_id = $manufacturer->id;
        $component->name = $overrides['name'] ?? 'Test Part';
        $component->slug = 'part-'.uniqid();
        $component->price = $overrides['price'] ?? 380.0;
        $component->currency = 'GBP';
        $component->stock = $overrides['stock'] ?? 5;
        $component->active = 1;
        $component->source_url = $overrides['source_url'] ?? 'https://uk.pcpartpicker.com/product/x/y';
        $component->specs = $overrides['specs'] ?? [];
        $component->save();

        return $component->fresh();
    }

    private function feedFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'awinfeed').'.csv';
        file_put_contents($path, $this->feedCsv());

        return $path;
    }

    public function test_it_reports_coverage_without_a_feed(): void
    {
        $this->makeComponent();

        $this->artisan('prices:ingest-feed')
            ->expectsOutputToContain('active components : 1')
            ->expectsOutputToContain('verified (price_checked_at set): 0')
            ->expectsOutputToContain('enforce_freshness currently : OFF')
            ->assertSuccessful();
    }

    public function test_default_run_is_a_dry_run(): void
    {
        $component = $this->makeComponent([
            'name' => 'Corsair Vengeance RGB 32GB Kit',
            'specs' => ['gtin' => '0794025011271'],
        ]);

        $this->artisan('prices:ingest-feed', ['file' => $this->feedFile()])
            ->expectsOutputToContain('matched by GTIN (high): 1')
            ->expectsOutputToContain('DRY RUN - nothing written')
            ->assertSuccessful();

        $fresh = $component->fresh();
        $this->assertNull($fresh->price_checked_at, 'a dry run must not stamp anything');
        $this->assertEquals(380.0, $fresh->price);
    }

    public function test_write_stamps_a_high_confidence_match(): void
    {
        $component = $this->makeComponent([
            'name' => 'Corsair Vengeance RGB 32GB Kit',
            'specs' => ['gtin' => '0794025011271'],
        ]);

        $this->artisan('prices:ingest-feed', [
            'file' => $this->feedFile(),
            '--write' => true,
        ])->assertSuccessful();

        $fresh = $component->fresh();
        $this->assertNotNull($fresh->price_checked_at, 'a written match must stamp the row');
        $this->assertEquals(379.99, $fresh->price);
        // The PCPP source_url is the refresh handle and must survive.
        $this->assertSame('https://uk.pcpartpicker.com/product/x/y', $fresh->source_url);
        $this->assertSame('https://www.scan.co.uk/products/ram', $fresh->specs['price_evidence_url']);
    }

    public function test_out_of_stock_feed_rows_are_not_written(): void
    {
        $component = $this->makeComponent([
            'name' => 'AMD Ryzen 7 5800X',
            'specs' => ['mpn' => '2000913RKHXBQ'],
            'price' => 320.0,
        ]);

        $this->artisan('prices:ingest-feed', [
            'file' => $this->feedFile(),
            '--write' => true,
            '--min-confidence' => 'medium',
        ])->assertSuccessful();

        $this->assertNull($component->fresh()->price_checked_at,
            'a feed row that is not in stock cannot evidence a sellable price');
    }

    public function test_a_name_only_match_is_not_written_at_the_default_confidence(): void
    {
        // No GTIN and no MPN on either side: a name-only (low) match.
        $component = $this->makeComponent(['name' => 'Fractal Define R5', 'price' => 199.0]);

        $this->artisan('prices:ingest-feed', [
            'file' => $this->feedFile(),
            '--write' => true,
        ])->assertSuccessful();

        $this->assertNull($component->fresh()->price_checked_at,
            'the default min-confidence is high, so a name-only match must be skipped');
    }

    public function test_a_suspicious_price_jump_is_reported_and_not_written(): void
    {
        // Catalogue GBP 10.00 vs feed GBP 379.99: far more likely a mis-match
        // than a real market move, so it must be refused.
        $component = $this->makeComponent([
            'name' => 'Corsair Vengeance RGB 32GB Kit',
            'specs' => ['gtin' => '0794025011271'],
            'price' => 10.0,
        ]);

        $this->artisan('prices:ingest-feed', [
            'file' => $this->feedFile(),
            '--write' => true,
        ])
            ->expectsOutputToContain('SUSPECT')
            ->assertSuccessful();

        $this->assertNull($component->fresh()->price_checked_at);
    }

    public function test_an_unknown_min_confidence_is_rejected(): void
    {
        $this->artisan('prices:ingest-feed', [
            'file' => $this->feedFile(),
            '--write' => true,
            '--min-confidence' => 'certain',
        ])->assertFailed();
    }

    public function test_download_without_a_credential_fails_loudly(): void
    {
        config(['services.awin.feed_api_key' => null]);

        $this->artisan('prices:ingest-feed', [
            '--download' => true,
            '--mid' => '1234',
        ])->assertFailed();
    }

    public function test_a_feed_file_with_no_name_column_fails_loudly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'awinbad').'.csv';
        file_put_contents($path, "id,price\n1,10.00\n");

        $this->artisan('prices:ingest-feed', ['file' => $path])->assertFailed();
    }
}
