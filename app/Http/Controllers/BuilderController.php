<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Services\AIRecommendationService;
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
    ) {
    }

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

        $cpu = $data['cpu_id'] ? Component::find($data['cpu_id']) : null;
        $gpu = Component::find($data['gpu_id']);

        return response()->json(
            $this->fps->forComponents($cpu, $gpu, $data['resolution'] ?? '1440P')
        );
    }

    /**
     * AI build generation from budget / purpose / resolution. Returns the full
     * catalog-shaped components so the Alpine store can populate `selected`.
     */
    public function ai(Request $request): JsonResponse
    {
        $data = $request->validate([
            'budget' => ['required', 'numeric', 'min:0'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
        ]);

        $recommendation = $this->recommendations->recommend(
            (float) $data['budget'],
            $data['purpose'] ?? null,
            $data['resolution'] ?? null,
            auth()->id()
        );

        $ids = collect($recommendation['components'])->pluck('id');
        $components = Component::query()
            ->with('category')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $catalog = [];

        foreach ($recommendation['components'] as $category => $item) {
            $component = $components[$item['id']] ?? null;

            if ($component !== null) {
                $catalog[$category] = $this->catalogItem($component);
            }
        }

        return response()->json([
            'components' => $catalog,
            'total' => $recommendation['total'],
            'remaining' => $recommendation['remaining'],
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

        $categories = ['cpu', 'motherboard', 'gpu', 'ram', 'storage', 'psu', 'case'];
        $selection = [];

        foreach ($categories as $category) {
            $item = $data['selection'][$category] ?? null;

            if (is_numeric($item)) {
                $component = Component::find((int) $item);
                $item = $component !== null ? [
                    'id' => $component->id,
                    'socket' => $component->socket,
                    'wattage' => $component->wattage,
                ] : null;
            }

            $selection[$category] = $item;
        }

        return response()->json($this->compatibility->summary($selection));
    }

    /**
     * @return array{id: int, slug: string, name: string, price: float, socket: ?string, wattage: ?int, stock: int, tags: ?string}
     */
    protected function catalogItem(Component $component): array
    {
        return [
            'id' => $component->id,
            'slug' => $component->slug,
            'name' => $component->name,
            'price' => (float) $component->price,
            'socket' => $component->socket,
            'wattage' => $component->wattage,
            'stock' => $component->stock,
            'tags' => $this->tagsFor($component),
        ];
    }

    protected function tagsFor(Component $component): ?string
    {
        return $component->tags;
    }
}
