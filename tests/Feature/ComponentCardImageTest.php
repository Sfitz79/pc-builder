<?php

/**
 * The saved-build page renders every part through x-pctg.component-card, and
 * that card's `image` prop used to be echoed with {{ $image }} - which ESCAPES.
 *
 * So the prop was unusable in both directions: pass a sensible <img src="...">
 * and the tag was printed to the customer as literal text; pass a URL and a
 * bare URL was printed. Nothing passed it at all, so the defect was invisible
 * until the component image backfill made real photos available - at which
 * point every one of the 2,681 components with a cached photo still showed no
 * photo on the build page.
 *
 * Asserted by RENDERING, not by reading the file, because the whole point of
 * the bug was that reading the file looked fine.
 */
namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ComponentCardImageTest extends TestCase
{
    private function renderCard(?string $image, ?string $price = null): string
    {
        return (string) Blade::render(
            '<x-pctg.component-card :title="$title" :subtitle="$subtitle" :image="$image" :price="$price">Compatible</x-pctg.component-card>',
            [
                'title' => 'AMD Ryzen 7 5800X',
                'subtitle' => 'CPU',
                'image' => $image,
                'price' => $price,
            ]
        );
    }

    public function test_a_url_becomes_a_real_img_tag_and_not_printed_text(): void
    {
        $html = $this->renderCard('/img/components/38941.jpg');

        $this->assertStringContainsString(
            'src="/img/components/38941.jpg"',
            $html,
            'the image URL must be rendered as an img src'
        );
        $this->assertSame(
            1,
            substr_count($html, '<img'),
            'exactly one img tag, and it must be the one the card builds'
        );
        $this->assertStringNotContainsString(
            '>/img/components/38941.jpg<',
            $html,
            'the URL must not be printed to the customer as text'
        );
    }

    public function test_the_price_carries_a_pound_sign_and_no_replacement_glyph(): void
    {
        $html = $this->renderCard(null, '189.99');

        $this->assertStringContainsString("\u{00A3}189.99", $html, 'the price must be prefixed with a real pound sign');
        $this->assertStringNotContainsString("\u{FFFD}", $html, 'U+FFFD replacement character reached the rendered output');
    }

    public function test_a_null_image_leaves_no_broken_img_tag(): void
    {
        $html = $this->renderCard(null);

        $this->assertStringNotContainsString('<img', $html, 'a null image must not leave an empty img tag');
        $this->assertStringContainsString('AMD Ryzen 7 5800X', $html, 'the part must still be named');
    }

    /**
     * The card is only useful if the build page actually hands it a photo.
     */
    public function test_the_build_page_passes_a_real_component_image_to_the_card(): void
    {
        $view = (string) file_get_contents(resource_path('views/builder/build.blade.php'));

        $this->assertStringContainsString(
            ':image=',
            $view,
            'build.blade.php must pass an image to x-pctg.component-card'
        );
        $this->assertStringContainsString(
            'displayImage()',
            $view,
            'it must use displayImage() so a missing photo degrades to the category placeholder'
        );
    }

    // Deliberately NO source-text assertion of the form "the card must not
    // contain {{ $image }}". It was written, it failed, and it was wrong: the
    // fix itself is src="{{ $image }}", which CONTAINS that substring, so the
    // check can only ever distinguish nothing. A test that cannot fail for the
    // right reason is false confidence. The rendering tests above prove the
    // behaviour, which is the only thing that matters here.
}
