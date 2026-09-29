<?php
// Genie 2026-09-29: identity matching is the only safe way to attach a merchant
// price to a catalogue row. A wrong match would reprice a real product, so the
// tiers and the fail-closed ambiguity rule are pinned here.
namespace Tests\Unit;

use App\Services\MerchantFeedMatcher;
use Tests\TestCase;

class MerchantFeedMatcherTest extends TestCase
{
    private function feedRow(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Part',
            'price' => 100.0,
            'in_stock' => true,
            'gtin' => null,
            'mpn' => null,
            'merchant_deep_link' => 'https://www.scan.co.uk/p/x',
            'deep_link' => null,
        ], $overrides);
    }

    private function comp(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Part',
            'sku' => null,
            'specs' => [],
        ], $overrides);
    }

    public function test_gtin_match_is_high_confidence(): void
    {
        $feed = [$this->feedRow(['name' => 'Entirely Different Name', 'gtin' => '0794025011271'])];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 7, 'name' => 'Corsair Vengeance 32GB', 'specs' => ['gtin' => '0794025011271']]),
        ]);

        $this->assertSame(1, $result['counts']['gtin']);
        $this->assertSame('high', $result['matches'][7]['confidence']);
        // A GTIN beats a name: these names differ on purpose.
        $this->assertSame(0, $result['counts']['name']);
    }

    public function test_a_bare_digit_sku_is_treated_as_a_gtin(): void
    {
        $feed = [$this->feedRow(['name' => 'Unrelated', 'gtin' => '1901980648299'])];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 3, 'name' => 'Asus TUF B650', 'sku' => '1901980648299']),
        ]);

        $this->assertSame(1, $result['counts']['gtin']);
    }

    public function test_a_non_gtin_length_sku_is_not_treated_as_a_trade_item_number(): void
    {
        // SKU "5068-01VG" is 8 characters but strips to 6 digits - not GTIN length,
        // so it must not be matched as if it were one.
        $feed = [$this->feedRow(['name' => 'Unrelated', 'gtin' => '506801VG'])];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 4, 'name' => 'Asus Card', 'sku' => '5068-01VG']),
        ]);

        $this->assertSame(0, $result['counts']['gtin']);
    }

    public function test_mpn_match_is_medium_confidence(): void
    {
        $feed = [$this->feedRow(['name' => 'Different Name', 'mpn' => 'CMW32GX2M2C3000K'])];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 9, 'name' => 'Corsair Kit', 'specs' => ['mpn' => 'CMW32GX2M2C3000K']]),
        ]);

        $this->assertSame(1, $result['counts']['mpn']);
        $this->assertSame('medium', $result['matches'][9]['confidence']);
    }

    public function test_unique_exact_name_is_low_confidence(): void
    {
        $feed = [$this->feedRow(['name' => 'Fractal Define R5 Black'])];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 11, 'name' => 'Fractal Define R5, Black']),
        ]);

        // Punctuation-only differences still match on the normalised name.
        $this->assertSame(1, $result['counts']['name']);
        $this->assertSame('low', $result['matches'][11]['confidence']);
    }

    public function test_ambiguous_names_fail_closed(): void
    {
        // Two feed rows share a name: picking either would evidence a random
        // product with a random price, so the name is dropped entirely.
        $feed = [
            $this->feedRow(['name' => 'Corsair Vengeance RGB 32GB', 'price' => 379.99]),
            $this->feedRow(['name' => 'Corsair Vengeance RGB 32GB', 'price' => 419.99]),
        ];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 12, 'name' => 'Corsair Vengeance RGB 32GB']),
        ]);

        $this->assertArrayNotHasKey(12, $result['matches']);
        $this->assertSame(1, $result['ambiguous_names']);
        $this->assertSame(1, $result['unmatched_components']);
    }

    public function test_one_feed_row_cannot_be_claimed_by_several_components(): void
    {
        // Real defect caught by its own test on 2026-09-29: three catalogue rows
        // sharing a GTIN all matched the same feed row, so one merchant price
        // was going to be stamped onto three products. A feed row is claimed by
        // at most one component.
        $feed = [$this->feedRow(['name' => 'Shared', 'gtin' => '0794025011271'])];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 1, 'specs' => ['gtin' => '0794025011271']]),
            $this->comp(['id' => 2, 'specs' => ['gtin' => '0794025011271']]),
            $this->comp(['id' => 3, 'specs' => ['gtin' => '0794025011271']]),
        ]);

        $this->assertCount(1, $result['matches']);
        $this->assertSame(1, $result['counts']['gtin']);
        $this->assertSame(2, $result['unmatched_components'],
            'the duplicate GTIN rows must fall through to unmatched, not share a price');
    }

    public function test_a_stronger_key_wins_over_a_unique_name(): void
    {
        // Both the name and the MPN match, but the MPN is the stronger signal.
        $feed = [
            $this->feedRow(['name' => 'Part', 'mpn' => 'MPN-1', 'price' => 111.0]),
        ];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 20, 'name' => 'Part', 'specs' => ['mpn' => 'MPN-1']]),
        ]);

        $this->assertSame(1, $result['counts']['mpn']);
        $this->assertSame(0, $result['counts']['name']);
    }

    public function test_counts_and_unmatched_rows_are_reported(): void
    {
        $feed = [
            $this->feedRow(['name' => 'A', 'gtin' => '1000000000001']),
            $this->feedRow(['name' => 'B', 'gtin' => '1000000000002']),
            $this->feedRow(['name' => 'C', 'gtin' => '1000000000003']),
        ];

        $result = (new MerchantFeedMatcher())->match($feed, [
            $this->comp(['id' => 1, 'name' => 'A', 'specs' => ['gtin' => '1000000000001']]),
        ]);

        $this->assertSame(1, $result['total_components']);
        $this->assertSame(1, $result['matched_rows']);
        $this->assertSame(2, $result['unmatched_rows']);
        $this->assertSame(0, $result['unmatched_components']);
    }

    public function test_it_accepts_eloquent_components_as_well_as_arrays(): void
    {
        $component = new \App\Models\Component();
        $component->id = 55;
        $component->name = 'Asus TUF';
        $component->specs = ['gtin' => '1901980648299'];
        $component->exists = true;

        $feed = [$this->feedRow(['name' => 'Whatever', 'gtin' => '1901980648299'])];

        $result = (new MerchantFeedMatcher())->match($feed, [$component]);

        $this->assertArrayHasKey(55, $result['matches']);
    }
}
