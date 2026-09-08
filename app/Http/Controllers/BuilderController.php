<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Services\AIRecommendationService;
use App\Services\BuildPricingService;
use App\Services\CompatibilityService;
use App\Services\FPSCalculationService;
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
     * AI build generation from budget / purpose / resolution. Returns two
     * catalog-shaped builds — the best-value build within the user's budget
     * AND the ideal (newest/best) build regardless of price.
     */
    public function ai(Request $request): JsonResponse
    {
        $data = $request->validate([
            'budget' => ['required', 'numeric', 'min:0'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
        ]);

        $recommendation = $this->recommendations->recommendBoth(
            (float) $data['budget'],
            $data['purpose'] ?? null,
            $data['resolution'] ?? null,
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

        return response()->json($builds);
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
}
