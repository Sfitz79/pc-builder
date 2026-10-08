<?php
// Genie 2026-10-08: pins the builder's dashboard column structure.
//
// WHAT THIS IS FOR. The live /builder page rendered five cards 57px wide and
// ~1471px tall while the card above them was a correct 299px. Measured in a real
// browser with Playwright:
//
//   3D Build View        299w  x=957   (correct - 4 grid tracks)
//   Build Summary         57w  x=312   OVERFLOWS
//   Compatibility         57w  x=393   OVERFLOWS
//   Upgrade Path to Ideal 57w  x=473   OVERFLOWS
//   Build Health          57w  x=554
//   Expected FPS          57w  x=635
//
// 57px x 12 + 11 gaps x 24 = 944px, which is exactly the width of #build-results,
// and the ~81px horizontal step is exactly one 56.67px grid track plus one 24px gap.
// So those five cards were NOT mis-sized - they had been ejected from their
// `<div class="lg:col-span-4 space-y-6">` column and become direct children of the
// 12-column grid, each confined to a single track.
//
// The browser named the container without inference:
//
//   col-span-4 children: 1   (3D Build View only)
//   #build-results children: 7
//     lg:col-span-8, lg:col-span-4, then 5 bare .pctg-card
//
// Cause: builder/partials/build-3d.blade.php is wrapped in <x-pctg.card>, which
// emits its own opening AND closing div. The partial also contained a literal
// </div>, which was therefore a SECOND close for the same element. The card ended
// early, the column wrapper ended with it, and the HTML parser silently discarded
// the surplus close - so the page still returned HTTP 200 and read as "a bit
// squished" rather than as broken. Counting <div> against </div> per card in the
// served HTML put card 2 (3D Build View) at delta -1, the only unbalanced one.
//
// WHY A TEST RATHER THAN A CODE REVIEW. The whole class of fault is invisible in a
// screenshot at a glance, invisible to an HTTP 200, and invisible to Blade's
// compiler - Blade does not validate tag balance. Only measuring the rendered DOM
// catches it, so that is what this does.
namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;

class BuilderColumnStructureTest extends TestCase
{
    /** Parse rendered HTML into a document we can assert against. */
    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        // The builder markup is UTF-8 and contains typographic characters; without
        // this the parser mangles them and can silently drop structure.
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    public function test_builder_dashboard_keeps_every_sidebar_card_inside_its_column(): void
    {
        $html = view('builder.dashboard')->render();
        $xpath = $this->dom($html);

        $grid = $xpath->query('//*[@id="build-results"]')->item(0);
        $this->assertNotNull($grid, '#build-results grid was not rendered at all.');

        // The eight-column results column and the four-column sidebar column.
        $left = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " lg:col-span-8 ")]', $grid)->item(0);
        $right = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " lg:col-span-4 ")]', $grid)->item(0);

        $this->assertNotNull($left, 'The lg:col-span-8 results column is missing.');
        $this->assertNotNull($right, 'The lg:col-span-4 sidebar column is missing.');

        // #build-results must hold ONLY the two column wrappers. Anything else that
        // is a .pctg-card has been ejected from its column and is now a direct child
        // of the 12-column grid, which confines it to a single ~57px track.
        //
        // Checked on the grid's DIRECT children rather than on every card in the
        // subtree, because a card may legitimately be nested inside another card -
        // upgrade-suggestions renders one - and demanding that every card's immediate
        // parent be a column produced a false positive on that nesting.
        $strays = [];
        foreach ($grid->childNodes as $child) {
            if (! ($child instanceof DOMElement)) {
                continue;
            }
            $cls = trim($child->getAttribute('class'));
            if ($child === $left || $child === $right) {
                continue;
            }
            $label = trim((string) ($xpath->query('.//h2 | .//h3', $child)->item(0)?->textContent ?? ''));
            $strays[] = ($label !== '' ? $label : '(no heading)') . ' [class="' . $cls . '"]';
        }

        $this->assertSame(
            [],
            $strays,
            'These elements sit directly under the 12-column grid rather than inside a column '
            . 'wrapper, so each is confined to a single ~57px track instead of a full column. '
            . 'This is the build-3d surplus-</div> fault: ' . implode(', ', $strays)
        );

        // And the sidebar column must actually hold the six cards it is meant to.
        $sidebarCards = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " pctg-card ")]', $right);
        $this->assertGreaterThanOrEqual(
            6,
            $sidebarCards->length,
            'The lg:col-span-4 column should hold the 3D view plus Build Summary, Compatibility, '
            . 'Upgrade Path, Build Health and Expected FPS. It holds ' . $sidebarCards->length . '.'
        );
    }

    /**
     * A DOM-level guard is the only one that works here, and an earlier static
     * version of this test was deleted on purpose.
     *
     * It tried to catch the fault by counting <div> against </div> in each partial,
     * and flagged four CORRECT files - build-summary, compatibility, component-grid
     * and fps-panel - which all legitimately end with `</div>` then `</x-pctg.card>`.
     * They are fine: <x-pctg.card> contributes one open and one close, so equal literal
     * counts are balanced.
     *
     * build-3d had EQUAL counts too (13/13) and was still broken, because the imbalance
     * came from @if blocks that render nothing when builder.enable_3d_viewport is off.
     * Counting literals cannot see that. Only rendering the view and inspecting the
     * resulting DOM can, which is what the test above does.
     */
}
