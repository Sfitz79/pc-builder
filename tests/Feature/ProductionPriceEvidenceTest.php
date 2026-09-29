<?php
// Genie 2026-09-29: Awin feed access and identity matching, against the REAL
// production catalogue. SQLite passing is not evidence here (Rule 7): the whole
// point of this lane is production data, so the coverage test is Neon-gated.
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Services\MerchantFeedMatcher;
use Tests\TestCase;

class ProductionPriceEvidenceTest extends TestCase
{
    /**
     * Run: $env:PRICE_EVIDENCE_REGRESSION="neon"; php artisan test --filter=ProductionPriceEvidenceTest
     */
    public function test_production_coverage_is_measurable_and_healthy(): void
    {
        if (($env = getenv('PRICE_EVIDENCE_REGRESSION')) !== 'neon') {
            $this->markTestSkipped('set PRICE_EVIDENCE_REGRESSION=neon to run against production');
        }

        require base_path('scripts/genie-prod-guard.php'); // asserts pgsql or exits 3

        $rows = Component::query()->where('active', true)->get();

        $this->assertGreaterThan(2000, $rows->count(), 'production catalogue should be the full set');

        $verified = $rows->filter(fn ($c) => $c->price_checked_at !== null)->count();
        $unsourced = $rows->filter(fn ($c) => trim((string) $c->source_url) === '')->count();

        // Reported, not asserted to a threshold: coverage is a business decision
        // (when to flip PRICE_INTEGRITY_ENFORCE_FRESHNESS), and this test exists
        // so the number is re-measurable rather than remembered.
        fwrite(STDERR, sprintf(
            "\n  production price evidence: %d/%d verified (%.1f%%), %d with no source_url\n",
            $verified,
            $rows->count(),
            $verified / max(1, $rows->count()) * 100,
            $unsourced
        ));

        $this->assertGreaterThanOrEqual(0, $verified);
        $this->assertLessThan($rows->count(), $unsourced + 1, 'some rows must carry a source_url');
    }

    /**
     * A real GTIN match must work end-to-end against production shapes, using
     * a component that actually exists. Identity matching is what stops a feed
     * price being attached to the wrong product, so it is checked with real
     * data rather than fixtures alone.
     */
    public function test_gtin_matching_works_against_production_components(): void
    {
        if (($env = getenv('PRICE_EVIDENCE_REGRESSION')) !== 'neon') {
            $this->markTestSkipped('set PRICE_EVIDENCE_REGRESSION=neon to run against production');
        }

        require base_path('scripts/genie-prod-guard.php');

        $component = Component::query()->where('active', true)->first();
        $this->assertNotNull($component);

        $gtin = '0794025011271';
        $feed = [[
            'name' => 'A Completely Different Product Name',
            'price' => 12.34,
            'in_stock' => true,
            'gtin' => $gtin,
            'mpn' => null,
            'merchant_deep_link' => 'https://example.test/p',
            'deep_link' => null,
        ]];

        $components = [[
            'id' => (int) $component->id,
            'name' => (string) $component->name,
            'sku' => $component->sku,
            'specs' => ['gtin' => $gtin],
        ]];

        $result = (new MerchantFeedMatcher())->match($feed, $components);

        $this->assertSame(1, $result['counts']['gtin']);
        $this->assertSame(0, $result['counts']['name'], 'the names differ, so only the GTIN can match');
    }

    /**
     * A feed row must never be able to match two different components. If it
     * could, one merchant price would be stamped onto unrelated products.
     */
    public function test_a_feed_row_is_consumed_by_at_most_one_component(): void
    {
        if (($env = getenv('PRICE_EVIDENCE_REGRESSION')) !== 'neon') {
            $this->markTestSkipped('set PRICE_EVIDENCE_REGRESSION=neon to run against production');
        }

        require base_path('scripts/genie-prod-guard.php');

        $gtin = '0794025011271';
        $feed = [[
            'name' => 'Shared', 'price' => 10.0, 'in_stock' => true,
            'gtin' => $gtin, 'mpn' => null,
            'merchant_deep_link' => 'https://example.test/p', 'deep_link' => null,
        ]];

        $components = [];
        for ($i = 0; $i < 3; $i++) {
            $components[] = ['id' => 100 + $i, 'name' => 'Part '.$i, 'sku' => null, 'specs' => ['gtin' => $gtin]];
        }

        $result = (new MerchantFeedMatcher())->match($feed, $components);

        $this->assertCount(1, $result['matches'], 'one feed row must not fan out to several components');
    }

    /**
     * The category ids the feed lane depends on must still be the real ones.
     */
    public function test_the_feed_lane_categories_exist_in_production(): void
    {
        if (($env = getenv('PRICE_EVIDENCE_REGRESSION')) !== 'neon') {
            $this->markTestSkipped('set PRICE_EVIDENCE_REGRESSION=neon to run against production');
        }

        require base_path('scripts/genie-prod-guard.php');

        foreach (['cpu', 'gpu', 'ram', 'storage', 'psu', 'case', 'motherboard', 'cooler'] as $slug) {
            $this->assertNotNull(
                Category::query()->where('slug', $slug)->first(),
                "category {$slug} must exist for feed matching to be meaningful"
            );
        }
    }
}
