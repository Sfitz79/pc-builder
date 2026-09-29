<?php

/**
 * The 3D build viewport has to be a BUILDER, not a one-shot preview.
 *
 * The viewport drew once when the panel was first shown and then went stale:
 * nothing in the Alpine store reacted to the selection changing, so swapping a
 * GPU or accepting an AI build left the old hardware in the case with nothing on
 * screen saying so. That defect is invisible to a PHP test, so the wiring is
 * asserted structurally here - the reactive watcher and the signature guard
 * are what make the scene track the build, and both are load-bearing.
 *
 * The render-bridge capability gate matters just as much commercially: the
 * "Storefront Render" button used to be shown unconditionally while the bridge
 * pointed at 127.0.0.1, which on a customer's machine is their own laptop. So
 * every real customer got a button that could only fail, after 150 seconds.
 */
namespace Tests\Feature;

use Tests\TestCase;

class ThreeDBuilderViewportTest extends TestCase
{
    private function js(string $file): string
    {
        $path = resource_path('js/'.$file);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function partial(): string
    {
        $path = resource_path('views/builder/partials/build-3d.blade.php');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The scene must rebuild when the selection changes. Without a deep watcher
     * on `selected`, every path that changes the build (the component picker,
     * the AI wizard, loading a saved build, the budget auto-swap) leaves the 3D
     * view showing the previous machine.
     */
    public function test_the_viewport_rebuilds_when_the_selection_changes(): void
    {
        $js = $this->js('builder.js');

        $this->assertMatchesRegularExpression(
            '/\$watch\(\s*[\'"]selected[\'"]/',
            $js,
            'builderState must watch the selection, or the 3D scene goes stale on every part swap'
        );

        $this->assertStringContainsString('{ deep: true }', $js,
            'the selection watcher must be deep: components are written as selected[category]');
    }

    /**
     * A watcher alone would rebuild the whole scene on unrelated state changes
     * and reset the camera. assemble() must be idempotent for a no-op rebuild.
     */
    public function test_a_rebuild_is_guarded_by_a_selection_signature(): void
    {
        $js = $this->js('pc-viewport.js');

        $this->assertStringContainsString('selectionSignature', $js,
            'assemble() must fingerprint the selection so a redundant call is free');

        $this->assertStringContainsString(
            'if (signature === lastSignature && buildGroup)',
            $js,
            'assemble() must bail out when nothing changed, before touching the scene'
        );
    }

    /**
     * Swapping a part must not yank the camera away from wherever the customer
     * had orbited to while inspecting their build.
     */
    public function test_the_camera_is_reframed_only_when_the_case_changes(): void
    {
        $js = $this->js('pc-viewport.js');

        $this->assertStringContainsString('lastCaseSignature', $js,
            'camera framing must be keyed to the case size, not every part swap');
    }

    /**
     * WebGL is not universal. A throw out of the renderer constructor left a
     * black rectangle and no explanation.
     */
    public function test_a_webgl_failure_is_reported_in_words_not_thrown(): void
    {
        $js = $this->js('pc-viewport.js');

        $this->assertStringContainsString('webgl-unavailable', $js,
            'a WebGL failure must reach the caller as a named failure');
        $this->assertStringContainsString('onFailure', $js);
        $this->assertStringContainsString('viewportUnavailableReason', $this->js('builder.js'));
    }

    /**
     * The dead-affordance guard. If the render bridge is the 127.0.0.1 default
     * it is the CUSTOMER's localhost, so the render can never succeed for them.
     */
    public function test_the_render_capability_endpoint_refuses_a_localhost_bridge(): void
    {
        config([
            'aigenstudio.comfyUrl' => 'http://127.0.0.1:8188',
            'aigenstudio.aigenUrl' => 'http://127.0.0.1:3000',
        ]);

        $response = $this->getJson('/builder/render-capability');

        $response->assertOk();
        $this->assertFalse($response->json('available'),
            'a localhost bridge must never be reported as available');
        $this->assertNotEmpty($response->json('reason'),
            'an unavailable bridge must explain itself, so the button can be hidden honestly');
    }

    /**
     * A configured-but-dead studio must also report unavailable. Probing a
     * guaranteed-closed port keeps the test fast and deterministic.
     */
    public function test_a_dead_render_studio_reports_unavailable(): void
    {
        // Port 9 (discard) with nothing listening: the connect fails immediately.
        config([
            'aigenstudio.comfyUrl' => 'http://127.0.0.1:9',
            'aigenstudio.aigenUrl' => 'http://studio.pctechguyonline.test',
        ]);

        $response = $this->getJson('/builder/render-capability');

        $response->assertOk();
        $this->assertFalse($response->json('available'));
        $this->assertStringContainsString('3D view', (string) $response->json('reason'),
            'the reason must reassure the customer that the build itself is unaffected');
    }

    /**
     * The capability response must never leak the internal host, and must not be
     * cached publicly (it is per-deployment, not per-customer).
     *
     * The hostname here is deliberately one that cannot resolve, because that is
     * the case that used to leak: Guzzle throws a ConnectionException whose
     * message contains the full URL, Laravel renders that message into the 500
     * body, and the endpoint answered 500 with the studio host in it. A probe
     * endpoint must never throw and must never echo what it was probing.
     */
    public function test_the_capability_response_does_not_leak_internal_hosts(): void
    {
        config([
            'aigenstudio.comfyUrl' => 'http://studio-internal.pctechguyonline.test:8188',
            'aigenstudio.aigenUrl' => 'http://studio-internal.pctechguyonline.test:3000',
        ]);

        $response = $this->getJson('/builder/render-capability');

        // A 500 here means the probe threw out of the endpoint, which is the
        // exact defect: the builder gets no answer and the host is published.
        $response->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('studio-internal', $body,
            'the internal studio hostname and port must not be published to the browser');
        $this->assertStringNotContainsString('8188', $body,
            'no internal port should be published either');
        $this->assertStringNotContainsString('curl', strtolower($body),
            'a transport error must be swallowed, not echoed to the browser');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * The button is gated on the capability flag, and the ComfyUI host is no
     * longer handed to the browser.
     */
    public function test_the_view_gates_the_button_and_stops_leaking_the_comfy_host(): void
    {
        $view = $this->partial();

        $this->assertStringContainsString('x-show="storefrontRenderAvailable"', $view,
            'the Storefront Render button must be gated on server-reported capability');

        $this->assertStringNotContainsString('aigenstudio.comfyUrl', $view,
            'the ComfyUI host must not be published to the customer browser');

        // The storefront-render copy must not promise the feature unconditionally.
        $this->assertStringContainsString('<template x-if="storefrontRenderAvailable">', $view,
            'the explanatory copy must not advertise a render that is unavailable');
    }

    /**
     * A part with no published dimensions must say so. Omitting the line reads
     * as "covered" when it means the opposite, which is the exact failure mode
     * the brand-integrity rules exist to prevent.
     */
    public function test_unknown_dimensions_are_stated_rather_than_hidden(): void
    {
        $view = $this->partial();

        $this->assertStringContainsString('dimensions not published for this part', $view);
    }

    /**
     * No mojibake in customer-facing copy. A previous pass left replacement
     * glyphs in the x-text labels, which render as literal boxes to a customer.
     */
    public function test_the_view_has_no_mojibake_or_replacement_glyphs(): void
    {
        $view = $this->partial();

        $this->assertStringNotContainsString("\u{FFFD}", $view, 'U+FFFD replacement character in customer copy');
        $this->assertStringNotContainsString('Renderingâ', $view, 'mangled ellipsis in the render button');
        $this->assertStringNotContainsString('orbit A', $view, 'mangled separator in the drag hint');
    }

    /**
     * three.js is ~600 kB of the app bundle and is only needed once a customer
     * opens the 3D panel. It must be a lazy chunk, not part of first paint.
     */
    public function test_three_is_not_bundled_into_the_main_entry_chunk(): void
    {
        $app = $this->js('app.js');

        $this->assertStringNotContainsString("import { mountPcViewport } from './pc-viewport'", $app,
            'a static import of pc-viewport drags the whole three.js library into the main bundle');

        $this->assertMatchesRegularExpression(
            '/import\([\'"]\.\/pc-viewport[\'"]\)/',
            $app,
            'pc-viewport must be loaded with a dynamic import so three.js is fetched on demand'
        );
    }
}
