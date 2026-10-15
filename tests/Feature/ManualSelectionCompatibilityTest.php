<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CompatibilityRule;
use App\Models\Component;
use App\Models\Build;
use App\Models\Manufacturer;
use App\Services\CatalogueGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualSelectionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_selection_uses_normalized_catalogue_sockets_for_server_validation(): void
    {
        $maker = Manufacturer::create(['name' => 'Test Parts', 'slug' => 'test-parts']);
        $cpuCategory = Category::create(['name' => 'CPU', 'slug' => 'cpu']);
        $boardCategory = Category::create(['name' => 'Motherboard', 'slug' => 'motherboard']);

        $cpu = Component::create([
            'category_id' => $cpuCategory->id,
            'manufacturer_id' => $maker->id,
            'name' => 'AMD Ryzen 5 5600',
            'slug' => 'test-ryzen-5-5600',
            'socket' => 'AM5',
            'active' => true,
        ]);
        $board = Component::create([
            'category_id' => $boardCategory->id,
            'manufacturer_id' => $maker->id,
            'name' => 'MSI MAG X870 TOMAHAWK WIFI',
            'slug' => 'test-x870-board',
            'socket' => 'AM5',
            'active' => true,
        ]);

        CompatibilityRule::create([
            'name' => 'CPU socket matches motherboard socket',
            'category' => 'cpu_motherboard',
            'rule_type' => 'socket_match',
            'conditions' => ['fail_closed' => true],
            'active' => true,
        ]);

        $this->getJson('/builder/catalog')
            ->assertOk()
            ->assertJsonPath('cpu.0.id', $cpu->id)
            ->assertJsonPath('cpu.0.platform', 'AM4')
            ->assertJsonPath('cpu.0.groupLabel', 'AM4 · Ryzen 5000 - Zen 3')
            ->assertJsonPath('cpu.0.manufacturer', 'Test Parts')
            ->assertJsonPath('motherboard.0.platform', 'AM5');

        $this->postJson('/builder/validate', [
            'selection' => [
                'cpu' => ['id' => $cpu->id],
                'motherboard' => ['id' => $board->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('cpuMotherboard', false)
            ->assertJsonPath('selectionValid', true);
    }

    public function test_unpersisted_or_stale_manual_selections_are_not_reported_as_verified(): void
    {
        $this->postJson('/builder/validate', [
            'selection' => [
                'cpu' => ['name' => 'Unpersisted CPU', 'socket' => 'AM5'],
                'motherboard' => 999999,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('selectionValid', false);
    }

    public function test_unknown_selected_sockets_fail_closed_but_unselected_parts_remain_valid(): void
    {
        $rule = new CompatibilityRule([
            'category' => 'cpu_motherboard',
            'rule_type' => 'socket_match',
            'active' => true,
        ]);

        $this->assertFalse($rule->evaluate([
            'cpu' => ['socket' => 'Unknown'],
            'motherboard' => ['socket' => 'Unknown'],
        ]));
        $this->assertTrue($rule->evaluate([
            'cpu' => ['socket' => 'am5'],
            'motherboard' => ['socket' => 'AM5'],
        ]));
        $this->assertTrue($rule->evaluate([
            'cpu' => ['socket' => 'AM5'],
        ]));
    }

    public function test_saved_build_uses_normalized_sockets_not_stale_database_columns(): void
    {
        $maker = Manufacturer::create(['name' => 'Test Parts', 'slug' => 'test-parts']);
        $ids = [];
        foreach (Component::REQUIRED_CATEGORIES as $categorySlug) {
            $category = Category::create(['name' => ucfirst($categorySlug), 'slug' => $categorySlug]);
            $name = match ($categorySlug) {
                'cpu' => 'AMD Ryzen 5 5600',
                'motherboard' => 'MSI MAG X870 TOMAHAWK WIFI',
                default => 'Test '.ucfirst($categorySlug),
            };
            $component = Component::create([
                'category_id' => $category->id,
                'manufacturer_id' => $maker->id,
                'name' => $name,
                'slug' => 'test-'.str_replace('_', '-', $categorySlug),
                'socket' => in_array($categorySlug, ['cpu', 'motherboard'], true) ? 'AM5' : null,
                'active' => true,
                'specs' => [],
            ]);
            $ids[] = ['category' => $categorySlug, 'id' => $component->id];
        }

        CompatibilityRule::create([
            'name' => 'CPU socket matches motherboard socket',
            'category' => 'cpu_motherboard',
            'rule_type' => 'socket_match',
            'conditions' => ['fail_closed' => true],
            'active' => true,
        ]);

        $this->postJson('/builder/builds', [
            'name' => 'Socket regression build',
            'components' => $ids,
        ])->assertCreated();

        $this->assertFalse(Build::query()->latest('id')->firstOrFail()->compatibility_checks['cpuMotherboard']);
    }

    public function test_selector_renders_grouped_catalogue_products(): void
    {
        $script = file_get_contents(resource_path('js/builder.js'));
        $selector = file_get_contents(resource_path('views/components/pctg/component-selector.blade.php'));

        $this->assertStringContainsString('filteredComponentGroups()', $script);
        $this->assertStringContainsString('item.groupLabel', $script);
        $this->assertStringContainsString('x-for="group in filteredComponentGroups()"', $selector);
        $this->assertStringContainsString('x-for="item in group.items"', $selector);
        $this->assertStringContainsString('pctg-selector-scroll', $selector);
        $this->assertStringContainsString('overflow-y: scroll', file_get_contents(resource_path('css/app.css')));
    }

    public function test_manual_selector_hides_part_prices_but_keeps_the_complete_build_price(): void
    {
        $script = file_get_contents(resource_path('js/builder.js'));
        $selector = file_get_contents(resource_path('views/components/pctg/component-selector.blade.php'));
        $summary = file_get_contents(resource_path('views/builder/partials/build-summary.blade.php'));

        $this->assertStringNotContainsString('item.price', $selector);
        $this->assertStringContainsString('sum + item.price', $script);
        $this->assertStringContainsString('livePrice.total', $summary);
        $this->assertStringContainsString('Total to pay', $summary);
    }

    public function test_custom_selector_groups_each_component_by_its_requested_specs(): void
    {
        $fixtures = [
            ['slug' => 'cpu', 'name' => 'AMD Ryzen 7 7800X3D', 'specs' => [], 'key' => 'AM5|Ryzen 7000 - Zen 4 (X3D)', 'label' => 'AM5 · Ryzen 7000 - Zen 4 (X3D)'],
            ['slug' => 'motherboard', 'name' => 'MSI MAG B650 TOMAHAWK WIFI', 'specs' => ['form_factor' => 'ATX'], 'key' => 'B650|AM5|ATX', 'label' => 'B650 · AM5 · ATX'],
            ['slug' => 'gpu', 'name' => 'NVIDIA GeForce RTX 5070 Ti', 'chipset' => 'GeForce RTX 5070 Ti', 'specs' => [], 'key' => 'GeForce RTX 5070 Ti', 'label' => 'GeForce RTX 5070 Ti'],
            ['slug' => 'storage', 'name' => 'Example NVMe Drive', 'specs' => ['type' => 'NVMe', 'capacity' => '2TB'], 'key' => 'NVMe|2TB', 'label' => 'NVMe · 2TB'],
            ['slug' => 'psu', 'name' => 'Example Modular PSU', 'wattage' => 850, 'specs' => [], 'key' => '850 W', 'label' => '850 W'],
            ['slug' => 'case', 'name' => 'Example Gaming Case', 'specs' => ['type' => 'ATX Mid Tower'], 'key' => 'ATX MID TOWER', 'label' => 'ATX Mid Tower'],
        ];

        foreach ($fixtures as $fixture) {
            $category = new Category(['name' => ucfirst($fixture['slug']), 'slug' => $fixture['slug']]);
            $component = new Component([
                'name' => $fixture['name'],
                'chipset' => $fixture['chipset'] ?? null,
                'wattage' => $fixture['wattage'] ?? null,
                'specs' => $fixture['specs'],
            ]);
            $component->setRelation('category', $category);

            $group = CatalogueGate::groupFor($component);

            $this->assertSame($fixture['key'], $group['key']);
            $this->assertSame($fixture['label'], $group['label']);
        }
    }

    public function test_manual_compatibility_response_checks_the_active_rules_for_selected_parts(): void
    {
        $maker = Manufacturer::create(['name' => 'Test Parts', 'slug' => 'test-parts']);
        $parts = [
            'cpu' => ['name' => 'AMD Ryzen 5 7600', 'socket' => 'AM4', 'wattage' => 65, 'specs' => []],
            'motherboard' => ['name' => 'MSI MAG B650 TOMAHAWK WIFI', 'socket' => 'AM4', 'specs' => [
                'form_factor' => 'ATX',
                'm2_slots' => ['PCIe 4.0 x4'],
                'pcie_slots' => ['PCIe 4.0 x16'],
            ]],
            'gpu' => ['name' => 'NVIDIA GeForce RTX 5070', 'wattage' => 400, 'specs' => [
                'length' => 350,
                'interface' => 'PCIe x16',
                'power_connector' => '8-pin',
            ]],
            'ram' => ['name' => '32GB DDR4 Memory', 'specs' => ['speed' => 3200, 'type' => 'DDR4']],
            'storage' => ['name' => 'SATA Solid State Drive', 'specs' => ['type' => 'SATA']],
            'psu' => ['name' => '600W Power Supply', 'wattage' => 600, 'specs' => []],
            'case' => ['name' => 'Mini Tower Case', 'specs' => [
                'supported_form_factors' => ['Micro-ATX'],
                'max_gpu_length' => 300,
            ]],
            'cooler' => ['name' => 'AM4 CPU Cooler', 'specs' => ['supported_sockets' => ['AM4']]],
        ];
        $selection = [];

        foreach ($parts as $slug => $attributes) {
            $category = Category::create(['name' => ucfirst($slug), 'slug' => $slug]);
            $component = Component::create(array_merge([
                'category_id' => $category->id,
                'manufacturer_id' => $maker->id,
                'slug' => 'test-'.$slug,
                'active' => true,
            ], $attributes));
            $selection[$slug] = ['id' => $component->id];
        }

        $this->assertSame(10, CompatibilityRule::query()->where('active', true)->count());

        $this->postJson('/builder/validate', ['selection' => $selection])
            ->assertOk()
            ->assertJsonPath('selectionValid', true)
            ->assertJsonPath('cpuMotherboard', true)
            ->assertJsonPath('ramSupported', false)
            ->assertJsonPath('powerEnough', false)
            ->assertJsonPath('gpuClearance', false)
            ->assertJsonPath('formFactorFits', false)
            ->assertJsonPath('coolerMount', false)
            ->assertJsonPath('storageSupported', false)
            ->assertJsonPath('pcieSlot', true);
    }
}
