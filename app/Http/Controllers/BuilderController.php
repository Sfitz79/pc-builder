<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Services\AIRecommendationService;
use App\Services\BuildPricingService;
use App\Services\CompatibilityService;
use App\Services\FPSCalculationService;
use App\Services\PartDimensions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $components = Component::query()
            ->with('category', 'manufacturer')
            ->active()
            ->get()
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
    protected function catalogItem(Component $component): array
    {
        $specsArray = is_array($component->specs) ? $component->specs : [];
        $category = $component->category?->slug;

        return [
            'id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'price' => (float) $component->price,
            'socket' => $component->socket,
            'chipset' => $component->chipset,
            'wattage' => $component->wattage,
            'stock' => $component->stock,
            'tags' => $this->tagsFor($component),
            'image' => $this->imageFor($component),
            'specs' => $specsArray,
            'dims' => $this->dimensions->resolve($category, $component->name, $specsArray),
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

        if (! filled($url) || ! str_starts_with($url, 'http')) {
            return $this->imagePlaceholderResponse($component);
        }

        try {
            // PCPartPicker's CDN 403s agents without a browser User-Agent/
            // Referer. Send polite browser headers so product textures load.
            // Guzzle must use the global cacert.pem or HTTPS product images
            // fail with "unable to get local issuer certificate".
            $cafile = 'C:\Users\simon\cacert.pem';
            $upstream = \Illuminate\Support\Facades\Http::timeout(15)
                ->withOptions(is_file($cafile) ? ['verify' => $cafile] : [])
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

        return response(\Illuminate\Support\Facades\File::get(public_path($path)), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
