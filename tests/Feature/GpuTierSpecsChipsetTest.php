<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Services\AIRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The P1 that reached production: a clamped-budget 1080p request came back with
 * a "Sparkle ECO" entry card in it, because AIRecommendationService read the GPU
 * model from the `chipset` COLUMN (null on every production GPU row) and fell
 * back to a title that had the model stripped out. The card therefore came back
 * tier 0 "no opinion" and failed open into the band.
 *
 * The model was never missing - it is in specs.chipset ("Arc A310"). This test
 * pins that reading so the defect cannot come back through a refactor.
 */
class GpuTierSpecsChipsetTest extends TestCase
{
    use RefreshDatabase;

    private function tierFor(?string $name, array $specs, ?string $column = null): int
    {
        $gpu = new Component([
            'name' => $name,
            'specs' => $specs,
            'active' => true,
            'price' => 100.0,
        ]);
        $gpu->chipset = $column;

        $m = new \ReflectionMethod(AIRecommendationService::class, 'gpuPerformanceTier');
        $m->setAccessible(true);

        return (int) $m->invoke(app(AIRecommendationService::class), $gpu);
    }

    public function test_entry_card_with_stripped_title_is_classified_from_specs_not_left_unclassified(): void
    {
        // This is the exact production row that caused the P1: title has no
        // model, chipset column is null, specs.chipset says "Arc A310".
        $tier = $this->tierFor('Sparkle ECO', ['memory' => '4GB', 'chipset' => 'Arc A310']);

        $this->assertSame(1, $tier,
            'An Arc A310 must classify as tier 1, NOT tier 0. Tier 0 means "no opinion" and fails open, which is how a 4GB entry card was sold as a 1080p machine.');
    }

    public function test_tier_zero_fails_the_1080p_band_promise(): void
    {
        // The whole point: whatever this method returns, the 1080p band demands
        // a real tier floor, so the card above must be refused for 1080p.
        $floor = (new \ReflectionMethod(AIRecommendationService::class, 'minGpuTierFor'))
            ->invoke(app(AIRecommendationService::class), '1080p');

        $this->assertGreaterThanOrEqual(2, (int) $floor,
            'The 1080p band must keep a real floor, otherwise a failed classification silently becomes a customer-facing promise.');

        $entryTier = $this->tierFor('Sparkle ECO', ['chipset' => 'Arc A310']);
        $this->assertLessThan((int) $floor, $entryTier,
            'A tier 1 card is below the 1080p floor and must be refused for that band, not slipped into it.');
    }

    public function test_midrange_and_strong_cards_classify_from_specs_chipset(): void
    {
        // CHANGED 2026-09-28: this previously asserted tier 3 and passed only
        // because the tier-3 pattern '/RTX 4070/' had no word boundary, which
        // also dragged the 4070 Ti and Ti Super down. The RTX 4070 is a strong
        // 1440p / entry-4K card, so tier 3 was wrong. See GpuTierLadderTest,
        // which pins the whole reconciled ladder.
        $this->assertSame(4, $this->tierFor('Asus DUAL OC', ['chipset' => 'GeForce RTX 4070']),
            'RTX 4070 is a strong 1440p / entry 4K card (tier 4), even behind a stripped title.');

        $this->assertSame(1, $this->tierFor('MSI VENTUS 2X E OC', ['chipset' => 'GeForce RTX 3050 6GB']),
            'RTX 3050 is tier 1.');

        $this->assertSame(5, $this->tierFor('MSI GAMING OC', ['chipset' => 'GeForce RTX 5070 Ti']),
            'RTX 5070 Ti is a proper 4K card (tier 5).');
    }

    public function test_chipset_column_still_wins_when_present(): void
    {
        // The column is the better field; do not let the specs fallback override
        // it when a future re-scrape populates it properly.
        $this->assertSame(5, $this->tierFor('MSI GAMING OC', ['chipset' => 'GeForce RTX 3050'], 'GeForce RTX 5090'));
    }
}
