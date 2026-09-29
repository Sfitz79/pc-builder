<?php

namespace Tests\Feature;

use App\Services\AIRecommendationService;
use App\Models\Component;
use Illuminate\Foundation\Testing\TestCase;
use ReflectionMethod;

/**
 * Pins the GPU tier ladder after the 2026-09-28 reconciliation.
 *
 * The ladder is not a matter of taste - it is what the 1080p/1440p/4K band
 * promises are enforced against, and two separate tables in
 * AIRecommendationService had drifted apart (the tier classifier rated an
 * RTX 4070 Ti Super as 1440p while the score table rated it 750).
 *
 * The reference points come from the measured Steam top-150 requirement set:
 * the most demanding publisher RECOMMENDED specs in the 100 most-played titles
 * ask for RX 6800 XT / RTX 3070 (8GB) with an i7-12700 or Ryzen 7 7800X3D, so a
 * card must not be able to satisfy a gate it cannot actually play at.
 */
class GpuTierLadderTest extends TestCase
{
    private function tier(string $name): int
    {
        $svc = app(AIRecommendationService::class);
        $m = new ReflectionMethod($svc, 'gpuPerformanceTier');
        $m->setAccessible(true);

        $component = new Component;
        $component->name = $name;
        $component->chipset = $name;
        $component->specs = ['chipset' => $name, 'vram' => '8GB'];

        return (int) $m->invoke($svc, $component);
    }

    public function test_rx_6800_xt_is_not_a_4k_flagship(): void
    {
        // Was tier 5, alongside RTX 5090 / 4090 / 7900 XTX. Warhammer 40,000:
        // Space Marine 2 recommends exactly "RX 6800 XT / RTX 3070" at 8GB.
        $this->assertSame(3, $this->tier('Radeon RX 6800 XT'), 'RX 6800 XT must sit at 3070 Ti class, not 4K flagship');
    }

    public function test_rx_6800_non_xt_matches_rx_6800_xt_band(): void
    {
        // Was tier 4 while the XT was tier 5 - the two halves of one card in
        // two different bands.
        $this->assertSame(3, $this->tier('Radeon RX 6800'));
    }

    public function test_rtx_4070_family_is_not_1440p_entry(): void
    {
        // Matched '/RTX 4070/' with no word boundary, so the Ti and Ti Super
        // variants fell into a bucket they exceed.
        $this->assertSame(4, $this->tier('GeForce RTX 4070'));
        $this->assertSame(4, $this->tier('GeForce RTX 4070 Ti'));
        $this->assertSame(4, $this->tier('GeForce RTX 4070 Ti SUPER'));
        $this->assertSame(4, $this->tier('MSI RTX 4070 Ti SUPER 16G VENTUS 3X BLACK OC'));
    }

    public function test_rtx_3070_ti_does_not_fall_into_the_plain_3070_band(): void
    {
        // Regression guard: tier 2 matches '/RTX 3070\b/', and \b matches at the
        // space in "RTX 3070 Ti", so the Ti needs its own tier-3 pattern.
        $this->assertSame(2, $this->tier('GeForce RTX 3070'));
        $this->assertSame(3, $this->tier('GeForce RTX 3070 Ti'));
    }

    public function test_rx_6700_xt_sits_with_rtx_3070_class(): void
    {
        // Near-equivalent to the RTX 3070 / 3070 Ti band.
        $this->assertSame(3, $this->tier('Radeon RX 6700 XT'));
    }

    public function test_1080p_ceiling_cards_all_clear_their_bands(): void
    {
        $this->assertSame(2, $this->tier('GeForce RTX 3060'));
        $this->assertSame(3, $this->tier('GeForce RTX 3060 Ti'));
        $this->assertSame(2, $this->tier('GeForce RTX 4060'));
        $this->assertSame(3, $this->tier('GeForce RTX 4060 Ti'));
    }

    public function test_true_flagships_stay_at_the_top(): void
    {
        foreach (['GeForce RTX 5090', 'GeForce RTX 4090', 'Radeon RX 7900 XTX', 'Radeon RX 9070 XT'] as $card) {
            $this->assertSame(5, $this->tier($card), $card.' should remain tier 5');
        }
    }
}
