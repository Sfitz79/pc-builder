<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Models\Manufacturer;
use App\Services\AIRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE CPU GENERATION FLOOR ON THE AI RECOMMENDATION PATH.
 *
 * Boss report 2026-10-13: the builder was still quoting builds with Ryzen 3600/3700/3900.
 *
 * The trap this test exists to prevent: BuildPolicyGate was ALREADY correct (it blocks all
 * pre-Zen3 parts), and three call sites already used it - BuilderController, SystemAssembler
 * and BudgetOptions. Every test that existed at the time passed. The bug was that
 * AIRecommendationService, the one path that writes into the auto-reply engine, never called
 * the gate at all: it filtered CPUs on "4+ cores" and "has a socket", then sorted cheapest
 * first, so a 6-core dirt-cheap Ryzen 5 3600 won every time.
 *
 * So this deliberately does NOT test the gate. The gate was never broken. This tests the
 * pool that the AI path actually picks from, because that is the thing that was wrong.
 */
class AiCpuGenerationFloorTest extends TestCase
{
    use RefreshDatabase;

    private function cpu(string $name, int $cores = 6, ?string $socket = 'AM4'): Component
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'Cpu']);
        $maker = Manufacturer::first() ?: Manufacturer::create([
            'name' => 'AMD', 'slug' => 'amd',
        ]);

        return Component::create([
            'category_id' => $category->id,
            'manufacturer_id' => $maker->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'active' => true,
            'socket' => $socket,
            'specs' => ['cores' => $cores],
        ]);
    }

    /** The exact pool the AI recommendation path picks from. */
    private function aiCpuPool(): \Illuminate\Support\Collection
    {
        $service = app(AIRecommendationService::class);
        $method = new ReflectionMethod(AIRecommendationService::class, 'viablePool');
        $method->setAccessible(true);

        $pool = Component::query()
            ->where('components.active', true)
            ->whereIn('components.category_id', Category::where('slug', 'cpu')->pluck('id'))
            ->get();

        return $method->invoke($service, 'cpu', $pool);
    }

    /**
     * @dataProvider obsoleteCpus
     */
    public function test_pre_zen3_ryzen_can_never_reach_the_ai_recommendation_pool(string $name): void
    {
        $this->cpu($name);

        $names = $this->aiCpuPool()->pluck('name')->all();

        $this->assertNotContains(
            $name,
            $names,
            "{$name} is pre-Zen3 and must never be quotable as a new build."
        );
    }

    public static function obsoleteCpus(): array
    {
        return [
            'ryzen 3 3200G' => ['AMD Ryzen 3 3200G'],
            'ryzen 3 3300X' => ['AMD Ryzen 3 3300X'],
            'ryzen 3 4100' => ['AMD Ryzen 3 4100'],
            'ryzen 5 1600' => ['AMD Ryzen 5 1600'],
            'ryzen 5 2600' => ['AMD Ryzen 5 2600'],
            'ryzen 5 3400G' => ['AMD Ryzen 5 3400G'],
            'ryzen 5 3600' => ['AMD Ryzen 5 3600'],
            'ryzen 5 3600X' => ['AMD Ryzen 5 3600X'],
            'ryzen 5 3600XT' => ['AMD Ryzen 5 3600XT'],
            'ryzen 5 4500' => ['AMD Ryzen 5 4500'],
            'ryzen 7 2700X' => ['AMD Ryzen 7 2700X'],
            'ryzen 7 3700X' => ['AMD Ryzen 7 3700X'],
            'ryzen 7 3800X' => ['AMD Ryzen 7 3800X'],
            'ryzen 9 3900X' => ['AMD Ryzen 9 3900X'],
            'ryzen 9 3950X' => ['AMD Ryzen 9 3950X'],
        ];
    }

    public function test_a_modern_ryzen_is_still_quotable_so_the_floor_is_not_over_broad(): void
    {
        $this->cpu('AMD Ryzen 5 5500', 6);
        $this->cpu('AMD Ryzen 5 5600X', 6);
        $this->cpu('AMD Ryzen 7 9800X3D', 8, 'AM5');

        $names = $this->aiCpuPool()->pluck('name')->all();

        $this->assertContains('AMD Ryzen 5 5500', $names);
        $this->assertContains('AMD Ryzen 5 5600X', $names);
        $this->assertContains('AMD Ryzen 7 9800X3D', $names);
    }

    public function test_the_floor_does_not_empty_the_pool(): void
    {
        $this->cpu('AMD Ryzen 5 3600');
        $this->cpu('AMD Ryzen 5 5600X');

        // If the floor rejected everything the builder could quote nothing at all, which
        // is a different outage. A broken fix is not a fix.
        $this->assertNotEmpty($this->aiCpuPool());
    }
}
