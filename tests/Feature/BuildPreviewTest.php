<?php

namespace Tests\Feature;

use App\Models\Component;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD PREVIEW IMAGE tests.
 *
 * These assert the REQUEST SHAPE we send to the render studio, not just that something came
 * back. The failure this guards against is a silent one: the endpoint returning 200 with an
 * empty or wrong image, which is the "green result that is quietly worse than intended"
 * trap. So every test checks either the outgoing URL/payload, or that a response without a
 * usable image is REJECTED rather than shown.
 */
class BuildPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const PROMPT_ID = '6f8a8f91-4c87-4e8a-915f-cf6c670b4b11';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aigenstudio.aigenUrl' => 'https://studio.example.test',
            'aigenstudio.comfyUrl' => 'https://comfy.example.test',
            'aigenstudio.mediaToken' => 'test-media-token',
            'aigenstudio.comfyToken' => 'test-comfy-token',
        ]);
        // The preview cache is a real file; keep tests out of storage/app.
        @unlink(storage_path('app/build-previews.json'));
    }

    private function makeComponent(string $categorySlug, string $name): Component
    {
        $category = \App\Models\Category::firstOrCreate(['slug' => $categorySlug], ['name' => ucfirst($categorySlug)]);
        $maker = \App\Models\Manufacturer::first() ?: \App\Models\Manufacturer::create([
            'name' => 'PCTechGuy Test',
            'slug' => 'pctechguy-test',
        ]);

        return Component::create([
            'category_id' => $category->id,
            'manufacturer_id' => $maker->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'active' => true,
        ]);
    }

    public function test_it_refuses_an_empty_build_instead_of_rendering_nothing(): void
    {
        $this->postJson('/builder/preview-image', ['component_ids' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_it_reports_missing_service_authentication_before_calling_the_studio(): void
    {
        $gpu = $this->makeComponent('gpu', 'Some GPU 1234');
        config()->set('aigenstudio.mediaToken', null);

        Http::fake();

        $this->postJson('/builder/preview-image', ['component_ids' => [$gpu->id]])
            ->assertStatus(503)
            ->assertJsonPath('configured', false)
            ->assertJsonPath('error', 'Image generation authentication is not configured for this site yet.');

        Http::assertNothingSent();
    }

    public function test_the_builder_preview_button_is_wired_to_the_preview_endpoint(): void
    {
        config()->set('builder.enable_3d_viewport', false);

        $html = view('builder.partials.build-3d')->render();
        $script = file_get_contents(resource_path('js/builder.js'));

        $this->assertStringContainsString('generateBuildPreview()', $html);
        $this->assertStringContainsString("previewImage: '/builder/preview-image'", $script);
        $this->assertStringContainsString('component_ids: componentIds', $script);
        $this->assertStringContainsString('X-CSRF-TOKEN', $script);
        $this->assertStringContainsString('buildPreviewUrl', $html);
        $this->assertStringContainsString('buildPreviewError', $html);
    }

    public function test_it_never_renders_a_deactivated_component(): void
    {
        $dead = $this->makeComponent('gpu', 'Retired Card 9000');
        $dead->update(['active' => false]);

        Http::fake([
            '*generate-media*' => Http::response(['image' => 'https://example.test/x.png']),
        ]);

        // A build made only of deactivated parts has nothing renderable, so it must not
        // silently render a generic machine - that would be a picture of nothing.
        $this->postJson('/builder/preview-image', ['component_ids' => [$dead->id]])
            ->assertStatus(404);
    }

    public function test_it_sends_the_real_part_names_to_the_studio(): void
    {
        $gpu = $this->makeComponent('gpu', 'Zotac GAMING AMP GeForce RTX 5080 16GB');
        $case = $this->makeComponent('case', 'Montech AIR 100 ARGB');
        $cpu = $this->makeComponent('cpu', 'AMD Ryzen 7 9800X3D');
        $board = $this->makeComponent('motherboard', 'MSI MAG X870 TOMAHAWK WIFI');
        $ram = $this->makeComponent('ram', 'Corsair Vengeance 32GB DDR5');
        $storage = $this->makeComponent('storage', 'Samsung 990 PRO 2TB');
        $psu = $this->makeComponent('psu', 'Corsair RM850x 850W');
        $cooler = $this->makeComponent('cooler', 'Arctic Liquid Freezer III 360');

        Http::fake([
            '*generate-media*' => Http::response([
                'provider' => 'grok',
                'model' => 'grok-imagine-image-2.0',
                'image_data_url' => 'data:image/jpeg;base64,ZmFrZQ==',
            ]),
        ]);

        $this->postJson('/builder/preview-image', ['component_ids' => [
            $gpu->id, $case->id, $cpu->id, $board->id, $ram->id, $storage->id, $psu->id, $cooler->id,
        ]])
            ->assertOk()
            ->assertJsonPath('provider', 'grok')
            ->assertJsonPath('model', 'grok-imagine-image-2.0')
            ->assertJsonPath('preview', 'data:image/jpeg;base64,ZmFrZQ==');

        Http::assertSent(function ($request) {
            $body = $request->data();

            if ($request->header('Authorization') !== ['Bearer test-media-token']) {
                return false;
            }
            if (($body['provider'] ?? null) !== 'grok' || ($body['noRef'] ?? null) !== true) {
                return false;
            }

            // The studio must be told the actual parts, or the render is a generic guess.
            if (($body['parts']['gpu'] ?? null) !== 'Zotac GAMING AMP GeForce RTX 5080 16GB') {
                return false;
            }
            if (($body['parts']['case'] ?? null) !== 'Montech AIR 100 ARGB') {
                return false;
            }
            foreach ([
                'cpu' => 'AMD Ryzen 7 9800X3D',
                'motherboard' => 'MSI MAG X870 TOMAHAWK WIFI',
                'ram' => 'Corsair Vengeance 32GB DDR5',
                'storage' => 'Samsung 990 PRO 2TB',
                'psu' => 'Corsair RM850x 850W',
                'cooler' => 'Arctic Liquid Freezer III 360',
            ] as $category => $name) {
                if (($body['parts'][$category] ?? null) !== $name) {
                    return false;
                }
            }

            $prompt = $body['prompt'] ?? '';
            return is_string($prompt)
                && str_contains($prompt, 'fully assembled')
                && str_contains($prompt, 'tempered-glass side panel')
                && str_contains($prompt, 'whole interior')
                && str_contains($prompt, 'exact graphics card')
                && str_contains($prompt, 'Zotac GAMING AMP GeForce RTX 5080 16GB')
                && str_contains($prompt, 'AMD Ryzen 7 9800X3D')
                && str_contains($prompt, 'Corsair Vengeance 32GB DDR5')
                && str_contains($prompt, 'Arctic Liquid Freezer III 360')
                && str_contains($prompt, 'do not use a solid side panel');
        });
    }

    public function test_it_rejects_a_response_with_no_usable_image(): void
    {
        $gpu = $this->makeComponent('gpu', 'Some GPU 1234');

        Http::fake([
            '*generate-media*' => Http::response(['ok' => true], 200),
        ]);

        // 200 with nothing usable must NOT be passed off as a working preview.
        $this->postJson('/builder/preview-image', ['component_ids' => [$gpu->id]])
            ->assertStatus(502);
    }

    public function test_the_builder_preview_button_uses_grok_and_displays_its_image_data_url(): void
    {
        $script = file_get_contents(resource_path('js/builder.js'));
        $controller = file_get_contents(app_path('Http/Controllers/BuildPreviewController.php'));

        $this->assertStringContainsString("typeof data?.preview === 'string'", $script);
        $this->assertStringContainsString("'provider' => 'grok'", $controller);
        $this->assertStringContainsString("'noRef' => true", $controller);
    }
}
