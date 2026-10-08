<?php
// Genie 2026-10-08: pins the PSU efficiency ranking to the SOURCED field.
//
// WHAT CHANGED. BuildPolicyGate::psuEfficiencyRank() used to parse the tier out of the
// product NAME, because the code carried a comment asserting there was no efficiency
// field anywhere for PSUs. Measured 2026-10-08, that was true of the database and
// false of the scrape: database/scraped/power-supply.json carries
// specs.efficiencyRating on 3,245 of 3,669 PSU rows (88.44%), and 496 of the 530
// seedable rows (93.58%). ScrapedCatalogSeeder's psu branch was assigning only
// $wattage, so 287 of 290 PSU rows had specs IS NULL and the guess was the only
// thing left to read.
//
// These tests pin the corrected order and, just as importantly, the two behaviours
// that must NOT regress into a fabrication:
//
//   1. the sourced field outranks the name, even when the name would disagree;
//   2. a bare '80+' is a real certification with no tier stated - it is NOT upgraded
//      to Gold and NOT demoted to Bronze;
//   3. a PSU with nothing stated is UNKNOWN, not Bronze;
//   4. the fallback is labelled, so a name-derived rating is never passed off as
//      sourced data.
namespace Tests\Feature;

use App\Models\Component;
use App\Services\BuildPolicyGate;
use Tests\TestCase;

class PsuEfficiencySourceTest extends TestCase
{
    private function psu(string $name, array $specs): Component
    {
        $c = new Component();
        $c->name = $name;
        $c->specs = $specs;
        $c->price = 100.0;
        $c->wattage = 750;

        return $c;
    }

    private function rank(Component $c): array
    {
        return (new BuildPolicyGate())->psuEfficiencyRank($c);
    }

    public function test_sourced_rating_outranks_the_product_name(): void
    {
        // A deliberately conflicting name. If the name won, a PSU sold as a
        // "Bronze" edition but certified Platinum would be ranked Bronze.
        $r = $this->rank($this->psu('Corsair RM750e Bronze Edition', [
            'efficiency_rating' => '80+ Platinum',
        ]));

        $this->assertSame('sourced', $r['source'], 'The sourced field must win over the name.');
        $this->assertSame('80+ Platinum', $r['rating']);
        $this->assertTrue($r['known']);
        $this->assertSame(2, $r['rank'], 'Platinum is the third entry in the prefer list, so rank 2.');
    }

    public function test_bare_80_plus_is_certified_but_tierless(): void
    {
        // 26 seedable rows state exactly this. Treating it as Gold invents a tier the
        // retailer never gave; treating it as Bronze misrepresents a certified unit.
        $r = $this->rank($this->psu('Thermaltake Smart BX1 650W', [
            'efficiency_rating' => '80+',
        ]));

        $this->assertTrue($r['known'], 'A bare 80+ is still a real certification.');
        $this->assertStringContainsString('tier not stated', $r['rating']);
        $this->assertSame(20, $r['rank'], 'Tierless 80+ ranks below every named tier.');
    }

    public function test_unstated_rating_is_unknown_not_bronze(): void
    {
        $r = $this->rank($this->psu('Some Unbranded 750W Power Supply', []));

        $this->assertFalse($r['known'], 'No rating stated anywhere means UNKNOWN.');
        $this->assertNull($r['rating']);
        $this->assertSame(99, $r['rank'], 'Unknown ranks last so it is never preferred.');
    }

    public function test_name_match_survives_as_a_labelled_fallback(): void
    {
        // ~6% of rows state no tier, and many of those names still carry it. The
        // fallback is worth keeping - but it must announce itself, or a name-derived
        // guess gets presented as though it were sourced data.
        $r = $this->rank($this->psu('be quiet! Pure Power 12 M 850W Gold', []));

        $this->assertSame('name-fallback', $r['source']);
        $this->assertTrue($r['known']);
        $this->assertSame('GOLD', $r['rating']);
    }

    public function test_efficiency_stays_a_ranking_and_never_a_gate(): void
    {
        // A hard gate here would reject every PSU whose retailer omitted a tier, and
        // failing closed on data we do not have would be dishonest. Unknown must rank
        // last rather than exclude the part.
        $unknown = $this->rank($this->psu('Unrated 650W Unit', []));
        $this->assertGreaterThan(
            $this->rank($this->psu('X 650W', ['efficiency_rating' => '80+ Gold']))['rank'],
            $unknown['rank'],
            'An unknown rating must rank below a stated Gold rating.'
        );
        $this->assertSame(
            99,
            $unknown['rank'],
            'Unknown must not be excluded outright - it is ranked, not banned.'
        );
    }
}
