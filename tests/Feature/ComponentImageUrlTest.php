<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Models\Manufacturer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Root-relative component images.
 *
 * Why this test exists: production APP_URL is not set in the Vercel env, so
 * config('app.url') is 'http://localhost'. The image_url stamper used to build
 * absolute URLs from it, which would have written http://localhost/... into all
 * 2,708 production rows and broken every product image on the live storefront.
 * These tests pin the root-relative contract and the local-file serving that
 * the 3D viewport depends on.
 */
class ComponentImageUrlTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = public_path('img/components');
    }

    protected function tearDown(): void
    {
        // The component id is an autoincrement, so the real filename is not
        // predictable from the test name. Track exactly what we created and
        // delete that - never glob, or a real cached image could be destroyed.
        foreach ($this->createdFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->createdFiles = [];
        parent::tearDown();
    }

    /** @var list<string> */
    private array $createdFiles = [];

    private function makeComponent(): Component
    {
        // components.manufacturer_id is NOT NULL with no default, so every
        // seeded part needs a real manufacturer row (same pattern as
        // BudgetBandsTest).
        $maker = Manufacturer::first() ?: Manufacturer::create([
            'name' => 'PCTechGuy Test',
            'slug' => 'pctechguy-test',
        ]);
        $category = Category::first() ?: Category::create(['name' => 'GPU', 'slug' => 'gpu']);

        return Component::create([
            'category_id' => $category->id,
            'manufacturer_id' => $maker->id,
            'name' => 'Test Card 99800',
            'slug' => 'test-card-99800',
            'active' => true,
        ]);
    }

    /**
     * A 1x1 red JPEG, so getimagesizefromstring() genuinely accepts it.
     */
    private function writeFakeJpeg(int $id): void
    {
        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $path = $this->cacheDir.'/'.$id.'.jpg';
        $im = imagecreatetruecolor(1, 1);
        imagefilledrectangle($im, 0, 0, 0, 0, imagecolorallocate($im, 255, 0, 0));
        imagejpeg($im, $path, 90);
        imagedestroy($im);
        $this->createdFiles[] = $path;
    }

    public function test_it_stamps_a_root_relative_url_not_an_absolute_one(): void
    {
        $component = $this->makeComponent();
        $this->writeFakeJpeg($component->id);

        // This is the production hazard, stated as an assertion: even with the
        // config at its default, no stamped value may contain a host. Run with
        // --apply on purpose: the write path itself was broken (the chunk
        // closure was missing $apply in its use list, so --apply silently wrote
        // nothing while reporting success).
        $this->artisan('components:populate-image-urls', ['--apply' => true])->assertSuccessful();

        $fresh = Component::find($component->id);
        $this->assertNotNull($fresh->image_url, '--apply must actually write');
        $this->assertStringStartsWith('/img/components/', $fresh->image_url);
        $this->assertStringNotContainsString('localhost', $fresh->image_url);
        $this->assertStringNotContainsString('http', $fresh->image_url);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $component = $this->makeComponent();
        $this->writeFakeJpeg($component->id);

        $this->artisan('components:populate-image-urls')->assertSuccessful();

        $this->assertNull(
            Component::find($component->id)->image_url,
            'a mass write to a live storefront must never be the default'
        );
    }

    public function test_a_component_with_no_cached_file_is_left_null_not_pointed_at_a_404(): void
    {
        $component = $this->makeComponent();
        // Deliberately no file written for this id.

        $this->artisan('components:populate-image-urls', ['--apply' => true])->assertSuccessful();

        $fresh = Component::find($component->id);
        $this->assertNull($fresh->image_url, 'must not be pointed at a file that does not exist');
    }

    public function test_an_existing_vendor_url_is_never_overwritten_without_force(): void
    {
        $component = $this->makeComponent();
        $this->writeFakeJpeg($component->id);
        $component->update(['image_url' => 'https://cdn.example.com/keep-me.jpg']);

        $this->artisan('components:populate-image-urls', ['--apply' => true])->assertSuccessful();

        $this->assertSame(
            'https://cdn.example.com/keep-me.jpg',
            Component::find($component->id)->image_url,
            'a good vendor URL must survive a non-forced run'
        );
    }

    public function test_part_image_serves_a_root_relative_cache_file_with_the_right_content_type(): void
    {
        $component = $this->makeComponent();
        $this->writeFakeJpeg($component->id);
        $component->update(['image_url' => '/img/components/'.$component->id.'.jpg']);

        $response = $this->get('/builder/part-image/'.$component->id);

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->getContent(), 'the image bytes must actually be returned');
    }

    public function test_part_image_redirects_when_the_file_is_not_on_this_filesystem(): void
    {
        // This is the production case: public/img/** is excluded from the lambda
        // and served by @vercel/static, so the file is absent from the function's
        // disk. A disk-only implementation would return a placeholder here and
        // the 3D viewport would lose every texture while looking fine locally.
        $component = $this->makeComponent();
        $component->update(['image_url' => '/img/components/999999-missing.jpg']);

        $response = $this->get('/builder/part-image/'.$component->id);

        $response->assertStatus(302);
        $this->assertStringEndsWith('/img/components/999999-missing.jpg', $response->headers->get('Location'));
    }

    public function test_part_image_returns_a_placeholder_not_a_500_when_there_is_no_image_url(): void
    {
        // MEASURED 2026-09-30 in production: /builder/part-image/41637 (a real
        // component, Zalman S2, image_url empty) returned HTTP 500. The
        // placeholder branch used to be an unconditional File::get(), which
        // throws in production because public/img/** is excluded from the
        // lambda. That is every one of the 2,022 components with no cached
        // photo, so the 3D viewport was broken for 75% of the catalogue.
        $component = $this->makeComponent();
        $component->update(['image_url' => null]);

        $response = $this->get('/builder/part-image/'.$component->id);

        $this->assertNotSame(500, $response->getStatusCode(),
            'a missing image must degrade to a placeholder, never a server error');
    }

    public function test_display_image_falls_back_when_there_is_no_image_at_all(): void
    {
        $component = $this->makeComponent();

        $display = $component->displayImage();

        $this->assertNotNull($display, 'a component must always render something');
    }
}
