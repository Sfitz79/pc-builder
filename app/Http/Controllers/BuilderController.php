<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Services\AIRecommendationService;
use App\Services\BuildPricingService;
use App\Services\CatalogueGate;
use App\Services\CompatibilityService;
use App\Services\FPSCalculationService;
use App\Services\PartDimensions;
use App\Services\ThreeD\BuildSceneService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\Factory as ViewFactory;

class BuilderController extends Controller
{
    public function __construct(
        protected ViewFactory $view,
        protected CompatibilityService $compatibility,
        protected FPSCalculationService $fps,
        protected AIRecommendationService $recommendations,
        protected BuildPricingService $pricing,
        protected PartDimensions $dimensions,
    ) {}

    public function dashboard(): View
    {
        return $this->view->make('builder.dashboard');
    }

    public function generate(): View
    {
        return $this->view->make('builder.dashboard');
    }

    public function manual(): View
    {
        return $this->view->make('builder.dashboard');
    }

    public function checkout(): View
    {
        return $this->view->make('builder.checkout');
    }

    public function payment(): View
    {
        return $this->view->make('builder.payment');
    }

    /**
     * JSON catalog shaped exactly like the Alpine store's `catalog` so the
     * frontend can swap its static array for real Eloquent data with zero
     * template changes.
     */
    public function catalog(): JsonResponse
    {
        // Modern gate. Read-only: nothing is deactivated or deleted, this is a
        // filter on what the storefront shows. Reversible by removing the
        // gateComponents() call.
        //
        // FAIL SAFE, deliberately. A customer-facing catalogue must never go dark
        // because a filter threw. If the gate errors for any reason we log it and
        // return the UNGATED catalogue, so the worst case is the pre-gate
        // behaviour the site already has - not a 500 on the builder. That is the
        // difference between a cosmetic regression and a dead shop.
        $components = $this->gateComponents(
            Component::query()->with('category', 'manufacturer')->active()->get()
        )
            ->groupBy(fn (Component $component) => $component->category?->slug ?? 'misc')
            ->map(fn ($items) => $items->map(fn (Component $component) => $this->catalogItem($component))->values());

        return response()->json($components);
    }

    /**
     * FPS estimates for a CPU + GPU pairing at a resolution.
     */
    public function fps(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cpu_id' => ['nullable', 'integer', 'exists:components,id'],
            'gpu_id' => ['required', 'integer', 'exists:components,id'],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
        ]);

        $cpu = ! empty($data['cpu_id']) ? Component::find($data['cpu_id']) : null;
        $gpu = Component::find($data['gpu_id']);

        return response()->json(
            $this->fps->forComponents($cpu, $gpu, $data['resolution'] ?? '1440P')
        );
    }

    /**
     * The price bands we can genuinely deliver, measured from the live
     * catalogue rather than typed in by hand.
     *
     * The budget slider, the budget input and the clamp all read this one
     * endpoint, so the smallest budget a customer can pick is always a budget
     * that really does produce a build (boss directive 2026-09-28). Includes
     * the WhatsApp contact used for the part-new/part-used alternative.
     */
    public function bands(): JsonResponse
    {
        return response()->json([
            'bands' => $this->recommendations->workableBands(),
            'whatsapp' => AIRecommendationService::WHATSAPP_NUMBER,
        ]);
    }

    /**
     * The procedural 3D geometry for the current selection, as a declarative
     * mesh spec in real millimetres.
     *
     * WHY A ROUND TRIP INSTEAD OF GENERATING IN THE BROWSER
     *
     * The geometry has to be generated in PHP, because the only way to prove
     * "the GPU is not a box, and its dimensions are real millimetres" is a PHP
     * script that can read PartDimensions and hash the output
     * (scripts/verify-3d-geometry.php). Generating in both languages would mean
     * two implementations quietly diverging, which is the failure mode this
     * whole project exists to remove.
     *
     * Three properties keep this off the critical path for a customer:
     *  - it is cached by selection signature, so a repeated swap of the same
     *    part costs nothing;
     *  - generation measures ~0.5ms, so a cold cache is not a stall either;
     *  - and it fails SAFE. A bad request, an unknown category or a generator
     *    that throws all return a 200 with `ok:false` and a reason, never a 500.
     *    The viewport then draws the legacy primitive scene. A dead shop is not
     *    an acceptable failure mode for a 3D preview.
     */
    public function meshSpec(Request $request): JsonResponse
    {
        $selection = $request->input('parts', []);
        if (! is_array($selection)) {
            return response()->json(['ok' => false, 'reason' => 'parts must be an object']);
        }

        // Only known categories, only real ids. Anything else is dropped rather
        // than trusted, so a crafted payload cannot make the endpoint load
        // arbitrary components or wander outside the catalogue.
        $clean = [];
        foreach (BuildSceneService::CATEGORIES as $category) {
            $id = (int) ($selection[$category]['id'] ?? $selection[$category] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $component = Component::with('category')->find($id);
            if (! $component || $component->category?->slug !== $category) {
                continue;
            }
            $clean[$category] = [
                'id' => $component->id,
                'name' => (string) $component->name,
                'specs' => is_array($component->specs) ? $component->specs : [],
            ];
        }

        if ($clean === []) {
            return response()->json(['ok' => false, 'reason' => 'no valid parts in the selection']);
        }

        $signature = 'pctg3d:v' . BuildSceneService::VERSION . ':' . sha1(json_encode($clean));

        try {
            $spec = Cache::remember($signature, 3600, function () use ($clean) {
                return app(BuildSceneService::class)->forSelection($clean);
            });
        } catch (\Throwable $e) {
            // Announce the fallback rather than degrading quietly (Rule 5): a
            // silent return of the legacy scene would look like "the new
            // geometry is just sparse on this part".
            Log::warning('[3d] mesh spec generation failed, client will draw the legacy scene', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'reason' => 'geometry generation failed',
                'signature' => $signature,
            ]);
        }

        return response()->json([
            'ok' => true,
            'signature' => $signature,
            'spec' => $spec,
        ]);
    }

    /**
     * AI build generation from budget / purpose / resolution. Returns two
     * catalog-shaped builds — the best-value build within the user's budget
     * AND the ideal (newest/best) build regardless of price.
     */
    public function ai(Request $request): JsonResponse
    {
        $data = $request->validate([
            'budget' => ['required', 'numeric', 'min:0'],
            'purpose' => ['nullable', Rule::in('gaming', 'streaming', 'creation', 'ai')],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
        ]);

        $resolution = $data['resolution'] ?? null;
        $asked = (float) $data['budget'];

        // Server-side clamp. The browser does this too, but a client is not
        // authority: a posted GBP 700 must not produce a quote that quietly
        // overshoots, it must be answered with the honest floor and the reason.
        $notice = $this->recommendations->budgetNotice($asked, $resolution);

        $budget = ($notice !== null && $notice['raised'])
            ? (float) $notice['min']
            : $asked;

        $recommendation = $this->recommendations->recommendBoth(
            $budget,
            $data['purpose'] ?? null,
            $resolution,
            auth()->id()
        );

        $builds = [];

        foreach (['budget', 'ideal'] as $which) {
            $build = $recommendation[$which] ?? [];

            $ids = collect($build['components'] ?? [])->pluck('id');
            $components = Component::query()
                ->with('category')
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            $catalog = [];

            foreach (($build['components'] ?? []) as $category => $item) {
                $component = $components[$item['id']] ?? null;

                if ($component !== null) {
                    $catalog[$category] = $this->catalogItem($component);
                }
            }

            $builds[$which] = [
                'components' => $catalog,
                'total' => $build['total'] ?? 0,
                'remaining' => $build['remaining'] ?? 0,
                'complete' => (bool) ($build['complete'] ?? false),
            ];

            if (! empty($build['ai'])) {
                $builds[$which]['ai'] = $build['ai'];
            }
        }

        // Always tell the client what it was quoted, and hand back the notice so
        // the UI can correct the input and explain itself in one round trip
        // rather than guessing client-side.
        return response()->json($builds + [
            'budget_asked' => $asked,
            'budget_used' => $budget,
            // Echoed so the response is self-describing: a clamped reply must
            // say which band it was judged against, otherwise the UI can only
            // guess and the explanation can contradict the machine.
            'resolution' => $resolution,
            'notice' => $notice,
        ]);
    }

    /**
     * Server-authoritative complete price for the current selection.
     *
     * Clients never price a build client-side: this endpoint applies the
     * hidden margin and returns ONE clean price. Perfect for the live summary
     * so the total shown in the configurator always matches checkout exactly.
     */
    public function price(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selection' => ['required', 'array'],
        ]);

        $ids = collect($data['selection'])->map(fn ($item) => (int) ($item['id'] ?? 0))
            ->filter(fn ($id) => $id > 0);

        $parts = (float) Component::query()
            ->whereIn('id', $ids)
            ->get()
            ->sum(fn (Component $component) => (float) $component->price);

        $complete = $this->pricing->completePrice($parts);
        $delivery = (float) config('pricing.build_delivery', 0);

        return response()->json([
            'parts_total' => $parts,
            'complete_price' => $complete,
            'build_delivery' => $delivery,
            'total' => round($complete + $delivery, 2),
        ]);
    }

    /**
     * Server-side compatibility summary for the current selection, matching the
     * shape of the Alpine store's `compatibility` state.
     */
    public function validate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selection' => ['required', 'array'],
        ]);

        $categories = ['cpu', 'motherboard', 'gpu', 'ram', 'storage', 'psu', 'case', 'cooler'];
        $selection = [];

        foreach ($categories as $category) {
            $item = $data['selection'][$category] ?? null;

            if (is_array($item)) {
                $id = (int) ($item['id'] ?? 0);
            } elseif (is_numeric($item)) {
                $id = (int) $item;
            } else {
                $id = 0;
            }

            $component = $id > 0 ? Component::find($id) : null;

            // Resolve from the database so every compatibility rule has access
            // to the same full spec data it gets at save/order time (socket,
            // wattage and specs). Passing raw catalog items through would drop
            // `specs` and make memory/clearance checks pass vacuously.
            $selection[$category] = $component?->toArray() ?? (is_numeric($item) ? null : $item);
        }

        return response()->json($this->compatibility->summary($selection));
    }

    /**
     * @return array{id: int, slug: string, name: string, price: float, socket: ?string, chipset: ?string, wattage: ?int, stock: int, tags: ?string, image: ?string}
     */
    /**
     * Apply the modern gate, falling back to the ungated list on any error.
     * See the note in catalog(): the gate is an enhancement and must never be
     * able to take the storefront offline.
     */
    protected function gateComponents($components)
    {
        try {
            return $components->reject(
                fn (Component $c) => CatalogueGate::rejectReason($c) !== null
            );
        } catch (\Throwable $e) {
            report($e);
            Log::warning('[catalogue] modern gate failed, serving ungated list', [
                'error' => $e->getMessage(),
            ]);
            return $components;
        }
    }

    protected function catalogItem(Component $component): array
    {
        $specsArray = is_array($component->specs) ? $component->specs : [];
        $category = $component->category?->slug;

        /*
         * PLATFORM IS DERIVED, NOT READ FROM THE COLUMN.
         *
         * Measured 2026-10-04: socket was NULL on 100 of 237 active CPUs and
         * WRONG on 86 more - Ryzen 3 3200G / 5 1600 / 5 3600 are all stored as
         * AM5 when they are AM4. chipset was NULL on 370 of 400 boards.
         *
         * This matters beyond display. CompatibilityCheckerService compares
         * $parts['cpu']['socket'] against $parts['motherboard']['socket'], so
         * feeding it the stored column let the custom part selector approve a
         * physically impossible pairing: a stored-AM5 Ryzen 5 3600 (really AM4)
         * "matched" an AM5 board. Emitting the derived value at this one point
         * fixes the checker and the grouping together, so there is a single
         * source of truth instead of two that can disagree.
         */
        $group = CatalogueGate::groupFor($component);
        $socket = match ($category) {
            'cpu' => CatalogueGate::cpuSocket((string) $component->name),
            'motherboard' => CatalogueGate::boardSocket((string) $component->name),
            default => $component->socket,
        };
        $chipset = $category === 'motherboard'
            ? CatalogueGate::chipset((string) $component->name)
            : $component->chipset;

        return [
            'id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'price' => (float) $component->price,
            'socket' => $socket,
            'chipset' => $chipset,
            'wattage' => $component->wattage,
            'stock' => $component->stock,
            'tags' => $this->tagsFor($component),
            'image' => $this->imageFor($component),
            'specs' => $specsArray,
            'dims' => $this->dimensions->resolve($category, $component->name, $specsArray),
            // Grouping metadata for the selection UI: CPUs by platform then
            // series, RAM by DDR generation, boards by chipset, storage by
            // interface. The UI reads these instead of re-deriving anything.
            'platform' => $group['platform'],
            'series' => $group['series'],
            'group' => $group['key'],
            'ram_type' => $category === 'ram'
                ? CatalogueGate::ramType((string) $component->name, $specsArray)
                : null,
            'storage_type' => $category === 'storage'
                ? CatalogueGate::storageType((string) $component->name, $specsArray)
                : null,
        ];
    }

    protected function imageFor(Component $component): ?string
    {
        return $component->displayImage();
    }

    protected function tagsFor(Component $component): ?string
    {
        return $component->tags;
    }

    /**
     * Same-origin image proxy for the 3D viewport's texture loader.
     *
     * The catalogue stores scraped/remote image URLs (PCPartPicker CDN etc.).
     * WebGL TextureLoader would taint/cross-origin-block those, so we proxy the
     * bytes server-side. Cheap: Laravel streams the upstream response straight
     * through.
     */
    public function partImage(Component $component): \Symfony\Component\HttpFoundation\Response
    {
        $url = $component->image_url;

        if (! filled($url)) {
            return $this->imagePlaceholderResponse($component);
        }

        // Root-relative local cache. Two environments, two correct answers:
        //
        //  - Local/dev: the file is in public/, so read and stream it.
        //  - Production: public/img/** is EXCLUDED from the lambda (it would
        //    otherwise blow the 250 MB function limit) and is served by
        //    @vercel/static at /img/(.*). The file is therefore NOT on the
        //    function's disk, so a disk read alone would silently return the
        //    placeholder in production while working fine locally.
        //
        // So: stream from disk when it is there, otherwise redirect to the same
        // root-relative path, which the edge serves. Neither path self-proxies
        // over HTTP, which is both slow and fragile in serverless.
        if (str_starts_with($url, '/')) {
            $path = public_path(ltrim($url, '/'));
            if (is_file($path)) {
                $body = @file_get_contents($path);
                if ($body !== false) {
                    $info = @getimagesizefromstring($body);
                    $contentType = 'image/jpeg';
                    if ($info !== false && ! empty($info['mime'])) {
                        $contentType = $info['mime'];
                    } elseif (str_ends_with(strtolower($url), '.png')) {
                        $contentType = 'image/png';
                    } elseif (str_ends_with(strtolower($url), '.webp')) {
                        $contentType = 'image/webp';
                    } elseif (str_ends_with(strtolower($url), '.gif')) {
                        $contentType = 'image/gif';
                    }

                    return response($body, 200, [
                        'Content-Type' => $contentType,
                        'Cache-Control' => 'public, max-age=86400',
                    ]);
                }
            }

            // Not on this filesystem: hand the browser the edge-served path.
            return redirect($url, 302, ['Cache-Control' => 'public, max-age=86400']);
        }

        if (! str_starts_with($url, 'http')) {
            return $this->imagePlaceholderResponse($component);
        }

        try {
            // PCPartPicker's CDN 403s agents without a browser User-Agent/
            // Referer. Send polite browser headers so product textures load.
            //
            // The CA bundle is resolved by App\Support\Tls, not hardcoded. This
            // line used to read
            //     $cafile = 'C:\Users\simon\cacert.pem';
            //     ->withOptions(is_file($cafile) ? ['verify' => $cafile] : [])
            // which could never be true in the deployed copy, so the `verify`
            // option was silently never applied there - it only ever worked on the
            // developer's machine, and it put a personal directory into the repo.
            $upstream = \Illuminate\Support\Facades\Http::timeout(15)
                ->withOptions(\App\Support\Tls::guzzleOptions())
                ->withHeaders([
                    'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    'Referer' => 'https://uk.pcpartpicker.com/',
                    'Sec-Fetch-Dest' => 'image',
                    'Sec-Fetch-Mode' => 'no-cors',
                    'Sec-Fetch-Site' => 'cross-site',
                ])
                ->get($url);

            if (! $upstream->successful()) {
                throw new \RuntimeException('upstream ' . $upstream->status());
            }

            $ct = $upstream->header('Content-Type') ?: 'image/jpeg';

            if (str_contains($ct, 'image/svg')) {
                $ct = 'image/svg+xml';
            }

            return response($upstream->body(), 200, [
                'Content-Type' => $ct,
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\Throwable) {
            return $this->imagePlaceholderResponse($component);
        }
    }

    /**
     * Render bridge: takes the 3D viewport data-URL, uploads it to ComfyUI,
     * then asks aigen's generate-media gateway to restyle the true-to-scale
     * geometry into a photoreal storefront render (SDXL refine, denoise 0.42
     * so the real layout is preserved). Returns the ComfyUI output filename.
     *
     * @return JsonResponse{prompt_id:string, reference:string, render:?string, error:?string}
     */
    /**
     * Is the storefront-render bridge actually usable right now?
     *
     * The 3D "Storefront Render" action sends the frame to aigen Studio's
     * ComfyUI. Three things can make that impossible, and all three used to
     * produce a button that looked available and then failed after a long wait:
     *
     *  1. The bridge host is still the 127.0.0.1 default. On a customer's
     *     machine that is THEIR laptop, not the studio, so the request can
     *     never succeed. Production must set AIGEN_URL/COMFYUI_URL to the
     *     studio host.
     *  2. The host is set but nothing is listening on it.
     *  3. It is the studio host, but unreachable from the deployed app
     *     (firewall / tunnel down).
     *
     * The client asks this before offering the button, so a dead affordance is
     * never shown. Answering is cheap and cached for a short window because
     * the check touches the network.
     */
    public function renderCapability(): JsonResponse
    {
        $configured = $this->renderBridgeConfigured();
        $reason = null;
        $available = false;

        if (! $configured['ok']) {
            $reason = $configured['reason'];
        } else {
            // 2s is deliberate. A render takes minutes; this only proves
            // something answers. Long enough to survive a slow tunnel,
            // short enough that the builder never feels stuck.
            //
            // The catch is NOT defensive padding. Guzzle THROWS a
            // ConnectionException on DNS failure, refused connection and TLS
            // error, and Laravel renders that exception's message - which
            // contains the full internal studio URL - straight into the HTTP
            // 500 body. So an unreachable studio produced a 500 that both broke
            // the capability check and published the internal host to anyone who
            // looked. A capability probe must never throw.
            try {
                $probe = \Illuminate\Support\Facades\Http::timeout(2)
                    ->withOptions(['http_errors' => false])
                    ->get($configured['comfyUrl'].'/system_stats');

                $available = $probe->successful();
            } catch (\Throwable $e) {
                // Intentionally swallowed and never echoed: the exception text
                // is exactly the host we must not disclose.
                $available = false;
            }

            if (! $available) {
                $reason = 'The render studio is not reachable at the moment. Your 3D view and build are unaffected - try the render again later.';
            }
        }

        // Deliberately NO host in this payload.
        //
        // The first version of this endpoint published the studio origin so the
        // frontend could show "studio online". That handed every visitor the
        // internal hostname and port of the render machine, which is both an
        // unnecessary disclosure and a free reconnaissance target for anyone who
        // knows how to look. The browser only needs a yes/no.
        return response()->json([
            'available' => $available,
            'reason' => $reason,
        ])->header('Cache-Control', 'private, max-age=60');
    }

    /**
     * Is the render bridge pointed at a real host, or still at the localhost
     * default that only works on the developer's own machine?
     *
     * @return array{ok: bool, reason: ?string, comfyUrl: string, studio: bool}
     */
    private function renderBridgeConfigured(): array
    {
        $comfyUrl = rtrim((string) config('aigenstudio.comfyUrl'), '/');
        $aigenUrl = rtrim((string) config('aigenstudio.aigenUrl'), '/');

        $localhost = static fn (string $url): bool => (bool) preg_match(
            '#^https?://(127\.0\.0\.1|localhost|\[::1\]|0\.0\.0\.0)(:|/|$)#i',
            $url
        );

        if ($localhost($comfyUrl) || $localhost($aigenUrl)) {
            return [
                'ok' => false,
                'studio' => false,
                'comfyUrl' => $comfyUrl,
                'reason' => 'Photoreal renders need the PCTG render studio, which is not configured for this site. The 3D view and your build are unaffected.',
            ];
        }

        return ['ok' => true, 'studio' => true, 'comfyUrl' => $comfyUrl, 'reason' => null];
    }

    public function render3d(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dataUrl' => ['required', 'string'],
            'prompt' => ['nullable', 'string', 'max:1000'],
            'parts' => ['nullable', 'array'],
            'denoise' => ['nullable', 'numeric', 'min:0.1', 'max:0.9'],
        ]);

        $baseUrl = config('aigenstudio.aigenUrl');
        $comfyUrl = config('aigenstudio.comfyUrl');

        // 1) Decode + upload the 3D snapshot to ComfyUI input.
        $dataUrl = $data['dataUrl'];
        if (! preg_match('#^data:image/(png|jpeg);base64,#i', $dataUrl, $m)) {
            return response()->json(['error' => 'dataUrl must be a base64 PNG/JPEG data URL.'], 422);
        }

        $bytes = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($bytes === false || ! $bytes) {
            return response()->json(['error' => 'Could not decode the base64 image.'], 422);
        }

        $ext = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
        $filename = 'pctg-3d-ref-'.time().'.'.$ext;

        $upload = \Illuminate\Support\Facades\Http::timeout(30)
            ->attach('image', $bytes, $filename)
            ->post($comfyUrl.'/api/upload/image', ['overwrite' => 'true']);

        if (! $upload->successful()) {
            return response()->json(['error' => 'ComfyUI upload failed: '.$upload->status()], 502);
        }

        $uploadedName = ($upload->json('name')) ?: $filename;

        // 2) Normalise the parts list into the shape generate-media expects.
        $partsMap = [];
        foreach (($data['parts'] ?? []) as $category => $item) {
            if (is_array($item) && filled($item['name'] ?? null)) {
                $partsMap[$category] = $item['name'];
            } elseif (is_string($item) && filled($item)) {
                $partsMap[$category] = $item;
            }
        }

        // When real part names are supplied, aigen's generate-media composes the
        // true-to-product storefront prompt itself ("professional studio product
        // photograph ... [exact GPU] ... liquid-cooling block ..."). Passing an
        // empty prompt keeps that grounded composition clean — no redundant lead.
        $prompt = $data['prompt'] ?? null;

        // No-parts fallback (generic callers): give aigen something to draw from.
        if (! $prompt && ! $partsMap) {
            $prompt = 'professional product photograph of a custom desktop PC, clean studio backdrop, soft lighting, sharp focus, storefront quality';
        }

        // 3) Call the aigen gateway. RealVisXL (SD) refine holds the 3D
        //    geometry's real layout instead of re-imagining it.
        try {
            $gw = \Illuminate\Support\Facades\Http::timeout(15)->post($baseUrl.'/api/generate-media', [
                'prompt' => $prompt,
                'mode' => 'image',
                'preset' => 'sd',
                'reference' => $uploadedName,
                'denoise' => $data['denoise'] ?? 0.42,
                'parts' => $partsMap ?: null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'aigen unreachable: '.$e->getMessage()], 502);
        }

        if (! $gw->successful()) {
            $body = $gw->json();
            return response()->json([
                'error' => 'aigen generate-media '.$gw->status().': '.($body['error'] ?? 'unknown'),
            ], $gw->status());
        }

        $promptId = $gw->json('prompt_id');

        if (! $promptId) {
            return response()->json(['error' => 'aigen returned no prompt_id.'], 502);
        }

        return response()->json([
            'prompt_id' => $promptId,
            'reference' => $uploadedName,
            'render' => null, // frontend polls ComfyUI history for completion
        ]);
    }

    protected function imagePlaceholderResponse(Component $component): \Symfony\Component\HttpFoundation\Response
    {
        $slug = $component->category?->slug;
        $path = match ($slug) {
            'case' => '/img/placeholders/case.svg',
            'gpu' => '/img/placeholders/gpu.svg',
            'cpu' => '/img/placeholders/cpu.svg',
            'motherboard' => '/img/placeholders/motherboard.svg',
            'ram' => '/img/placeholders/ram.svg',
            'storage' => '/img/placeholders/storage.svg',
            'psu' => '/img/placeholders/psu.svg',
            default => '/img/placeholders/cooler.svg',
        };

        // Same two-environment problem as partImage() above, with a much worse
        // failure mode: this used to be an unconditional File::get(), which
        // THROWS when the file is absent. In production public/img/** is
        // excluded from the lambda, so every component without a cached photo
        // 500'd here - that is 2,022 of 2,708 components, i.e. the 3D viewport
        // broke for 75% of the catalogue instead of showing a placeholder.
        if (is_file($full = public_path(ltrim($path, '/')))) {
            $body = @file_get_contents($full);
            if ($body !== false) {
                return response($body, 200, [
                    'Content-Type' => 'image/svg+xml',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        // Not on the function's filesystem: the edge serves it.
        return redirect($path, 302, ['Cache-Control' => 'public, max-age=86400']);
    }
}
