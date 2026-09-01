<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Component;
use App\Services\AIRecommendationService;
use App\Services\CompatibilityService;
use App\Services\FPSCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Factory as ViewFactory;

class BuildController extends Controller
{
    protected array $validCategories = [
        'cpu',
        'motherboard',
        'gpu',
        'ram',
        'storage',
        'psu',
        'case',
    ];

    public function __construct(
        protected ViewFactory $view,
        protected CompatibilityService $compatibility,
        protected FPSCalculationService $fps,
        protected AIRecommendationService $recommendations,
    ) {
    }

    public function index(Request $request): View|JsonResponse
    {
        $builds = Build::query()
            ->with('components.category', 'components.manufacturer')
            ->when(auth()->check(), fn ($query) => $query->forUser(auth()->id()))
            ->unless(auth()->check(), fn ($query) => $query->forOwnerToken($this->guestOwnerToken()))
            ->latest()
            ->get();

        if ($request->wantsJson()) {
            return response()->json(
                $builds->map(fn (Build $build) => $this->buildPayload($build))
            );
        }

        return $this->view->make('builder.builds', [
            'builds' => $builds,
        ]);
    }

    public function show(string $shareSlug): View
    {
        $build = Build::query()
            ->byShareSlug($shareSlug)
            ->with('components.category', 'components.manufacturer', 'user')
            ->firstOrFail();

        return $this->view->make('builder.build', [
            'build' => $build,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.category' => ['required', Rule::in($this->validCategories)],
            'components.*.id' => ['required', 'integer', 'exists:components,id'],
        ]);

        $selected = collect($data['components'])->keyBy('category');
        $components = Component::query()
            ->whereIn('id', $selected->pluck('id'))
            ->get()
            ->keyBy('id');

        if ($components->count() !== $selected->count()) {
            throw ValidationException::withMessages([
                'components' => 'One or more selected components no longer exist.',
            ]);
        }

        $total = $selected->sum(fn ($item) => (float) $components[$item['id']]->price);

        $selection = $this->selectionMap($selected, $components);

        $build = Build::create([
            'user_id' => auth()->id(),
            'owner_token' => auth()->check() ? null : $this->guestOwnerToken(),
            'name' => $data['name'],
            'purpose' => $data['purpose'] ?? null,
            'resolution' => $data['resolution'] ?? null,
            'budget' => $data['budget'] ?? null,
            'total_price' => $total,
            'performance_score' => $this->compatibility->score($selection),
            'compatibility_checks' => $this->compatibility->summary($selection),
            'share_slug' => Str::random(10),
        ]);

        foreach ($selected as $category => $item) {
            $build->components()->attach($item['id'], [
                'category' => $category,
                'price_snapshot' => $components[$item['id']]->price,
            ]);
        }

        return response()->json([
            'build' => $build,
            'share_url' => $build->shareUrl,
            'total' => $build->formattedTotal,
            'performance_score' => $build->performance_score,
            'fps' => $this->fps->forComponents(
                $components[$selected['cpu']['id'] ?? null] ?? null,
                $components[$selected['gpu']['id'] ?? null] ?? null,
                $data['resolution'] ?? '1440P'
            ),
        ], 201);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'budget' => ['required', 'numeric', 'min:0'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
        ]);

        $result = $this->recommendations->recommend(
            (float) $data['budget'],
            $data['purpose'] ?? null,
            $data['resolution'] ?? null,
            auth()->id()
        );

        return response()->json($result);
    }

    public function update(Request $request, Build $build): RedirectResponse|JsonResponse
    {
        abort_unless($this->owns($build), 403);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
        ]);

        $build->update(array_filter($data));

        return back();
    }

    public function destroy(Request $request, Build $build): RedirectResponse|JsonResponse
    {
        abort_unless($this->owns($build), 403);

        $build->delete();

        return back();
    }

    /**
     * Whether the current request may manage this build: the authenticated
     * owner, or a guest holding the build's session owner token.
     */
    protected function owns(Build $build): bool
    {
        if (auth()->check()) {
            return $build->isOwnedBy(auth()->user());
        }

        return $build->isOwnedByToken($this->guestOwnerToken());
    }

    /**
     * Stable per-session owner token used to scope guest builds.
     */
    protected function guestOwnerToken(): string
    {
        if (! session()->has('guest_owner_token')) {
            session()->put('guest_owner_token', Str::random(40));
        }

        return (string) session('guest_owner_token');
    }

    /**
     * Catalog-shaped serialization so the Alpine store can restore a build with
     * zero template changes. Prices come from the save-time snapshot.
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(Build $build): array
    {
        return [
            'id' => $build->id,
            'name' => $build->name,
            'purpose' => $build->purpose,
            'resolution' => $build->resolution,
            'budget' => $build->budget !== null ? (float) $build->budget : null,
            'total_price' => (float) $build->total_price,
            'share_url' => $build->shareUrl,
            'components' => $build->components
                ->sortBy(fn ($component) => array_search(
                    $component->pivot->category,
                    $this->validCategories
                ))
                ->values()
                ->map(fn ($component) => [
                    'category' => $component->pivot->category,
                    'id' => $component->id,
                    'slug' => $component->slug,
                    'name' => $component->name,
                    'price' => (float) $component->pivot->price_snapshot,
                    'socket' => $component->socket,
                    'wattage' => $component->wattage,
                    'stock' => (int) $component->stock,
                    'tags' => $component->tags,
                ]),
        ];
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    protected function selectionMap($selected, $components): array
    {
        $map = [];

        foreach ($this->validCategories as $category) {
            $item = $selected->get($category);

            $map[$category] = $item !== null
                ? $components[$item['id']]->toArray()
                : null;
        }

        return $map;
    }
}
