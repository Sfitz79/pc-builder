<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Component;
use App\Models\User;
use Tests\TestCase;

/**
 * The 3D viewport's geometry is generated in PHP from the catalogue's real
 * millimetre dimensions, so these tests can assert things a JavaScript test
 * could not: that the generators actually differ from each other, that nothing
 * is a single box, and that no branding is burned into rendered content.
 */
class ThreeDGeometryTest extends TestCase
{
    private function service(): \App\Services\ThreeD\BuildSceneService
    {
        return new \App\Services\ThreeD\BuildSceneService(
            app(\App\Services\PartDimensions::class)
        );
    }

    /**
     * The brief's gate, asserted rather than eyeballed: a graphics card is not
     * a box. If this ever degrades to one primitive the approach is wrong.
     */
    public function test_the_gpu_is_not_a_single_box(): void
    {
        $gpu = app(\App\Services\ThreeD\GpuGeometry::class)->generate(
            ['x' => 336, 'y' => 150, 'z' => 61.0, 'slots' => 3],
            ['chipset' => 'GeForce RTX 4090', 'memory' => '24GB'],
            'ASUS TUF Gaming GeForce RTX 4090 OC 24GB',
            'gpu'
        );

        $this->assertGreaterThan(
            25,
            count($gpu['meshes']),
            'a reference GPU must be a built assembly, not a slab'
        );

        $shapes = array_count_values(array_column($gpu['meshes'], 'p'));
        foreach (['box', 'cyl', 'ring', 'fan', 'stack'] as $shape) {
            $this->assertArrayHasKey(
                $shape,
                $shapes,
                "a graphics card needs {$shape} primitives to read as a graphics card"
            );
        }

        // The features the brief names explicitly.
        $materials = array_column($gpu['meshes'], 'm');
        foreach (['shroudAsus', 'backplate', 'fin', 'gold', 'bracket', 'connector'] as $required) {
            $this->assertContains(
                $required,
                $materials,
                "the GPU generator is missing its {$required}"
            );
        }
    }

    /**
     * Variation must come from real specs, not a random number. Two cards with
     * different published specs must not generate identical geometry.
     */
    public function test_gpu_variation_follows_real_specs(): void
    {
        $flagship = app(\App\Services\ThreeD\GpuGeometry::class)->generate(
            ['x' => 336, 'y' => 150, 'z' => 61.0, 'slots' => 3],
            ['chipset' => 'GeForce RTX 5090', 'memory' => '32GB'],
            'PNY GeForce RTX 5090 32GB',
            'gpu'
        );

        $entry = app(\App\Services\ThreeD\GpuGeometry::class)->generate(
            ['x' => 242, 'y' => 111, 'z' => 40.6, 'slots' => 2],
            ['chipset' => 'GeForce RTX 3050', 'memory' => '8GB'],
            'MSI VENTUS 2X GeForce RTX 3050 8GB',
            'gpu'
        );

        $this->assertNotSame(
            json_encode($flagship['meshes']),
            json_encode($entry['meshes']),
            'a flagship and an entry card must not generate the same geometry'
        );

        $this->assertGreaterThan(
            $entry['meta']['fan_count'],
            $flagship['meta']['fan_count'],
            'a 5090 must carry more shroud fans than a 3050'
        );

        $this->assertGreaterThan(
            $entry['meta']['connector_pins'],
            $flagship['meta']['connector_pins'],
            'a 5090 must carry the modern 16-pin power connector, not a 6-pin'
        );
    }

    /**
     * Determinism is a product requirement, not a nicety: the same product must
     * render identically on every visit, otherwise a customer's saved build
     * looks like a different machine each time they load it.
     */
    public function test_generators_are_deterministic(): void
    {
        $service = $this->service();
        $selection = [
            'case' => ['id' => 1, 'name' => 'Lian Li O11 Vision', 'specs' => []],
            'gpu' => ['id' => 2, 'name' => 'PNY RTX 5080', 'specs' => ['chipset' => 'GeForce RTX 5080', 'memory' => '16GB']],
            'cpu' => ['id' => 3, 'name' => 'AMD Ryzen 7 9800X3D', 'specs' => []],
            'ram' => ['id' => 4, 'name' => 'Corsair Vengeance RGB Pro 32 GB', 'specs' => ['speed' => 'DDR5-6000', 'capacity' => '32 GB']],
            'psu' => ['id' => 5, 'name' => 'Corsair RM850x', 'specs' => ['wattage' => 850]],
        ];

        $first = json_encode($service->forSelection($selection));

        // A fresh instance, so nothing is being served out of a static cache.
        $second = json_encode($this->service()->forSelection($selection));

        $this->assertSame($first, $second, 'the same selection must generate byte-identical geometry');
        $this->assertNotFalse(json_decode((string) $first, true));
    }

    /**
     * Nothing generated may carry PCTechGuy branding. TikTok's content sharing
     * guidelines treat burned-in branding or watermarks on rendered content as
     * a violation that gets content deleted and accounts disabled, so this is a
     * hard rule and it is asserted, not remembered.
     */
    public function test_no_generated_geometry_carries_branding(): void
    {
        $service = $this->service();
        $scene = $service->forSelection([
            'case' => ['id' => 1, 'name' => 'Lian Li O11 Vision', 'specs' => []],
            'gpu' => ['id' => 2, 'name' => 'MSI GeForce RTX 5080 VENTUS 3X', 'specs' => ['chipset' => 'GeForce RTX 5080', 'memory' => '16GB']],
        ]);

        $blob = json_encode($scene);

        foreach (['pctg', 'PCTechGuy', 'PCTECHGUY', 'watermark'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                (string) $blob,
                "generated 3D content must never carry '{$forbidden}'"
            );
        }
    }

    /**
     * Every category in the brief has a generator, and each produces geometry.
     */
    public function test_every_category_has_a_generator(): void
    {
        $generators = [
            'gpu' => \App\Services\ThreeD\GpuGeometry::class,
            'cpu' => \App\Services\ThreeD\CpuGeometry::class,
            'motherboard' => \App\Services\ThreeD\MotherboardGeometry::class,
            'ram' => \App\Services\ThreeD\RamGeometry::class,
            'storage' => \App\Services\ThreeD\StorageGeometry::class,
            'psu' => \App\Services\ThreeD\PsuGeometry::class,
            'case' => \App\Services\ThreeD\CaseGeometry::class,
            'cooler' => \App\Services\ThreeD\CoolerGeometry::class,
            'fan' => \App\Services\ThreeD\CaseFans::class,
        ];

        foreach ($generators as $category => $class) {
            $generator = app($class);

            $dims = app(\App\Services\PartDimensions::class)->resolve($category, 'Test Part', []);
            $result = $generator->generate($dims, [], 'Test Part', $category);

            $meshes = $result['meshes']
                ?? array_merge(...array_column($result['groups'] ?? [], 'meshes'));

            $this->assertNotEmpty($meshes, "the {$category} generator produced no geometry");
            $this->assertNotEmpty($generator->localFrame(), "{$category} must document its local frame");
        }
    }

    /**
     * A 360mm AIO and a 160mm air tower are different objects. Drawing them the
     * same would make the cooler field decorative.
     */
    public function test_cooler_variation_follows_real_dimensions(): void
    {
        $generator = app(\App\Services\ThreeD\CoolerGeometry::class);
        $dims = app(\App\Services\PartDimensions::class);

        $air = $generator->generate($dims->resolve('cooler', 'Noctua NH-D15', []), [], 'Noctua NH-D15', 'cooler');
        $aio = $generator->generate($dims->resolve('cooler', 'ARCTIC Liquid Freezer III 360', []), [], 'ARCTIC Liquid Freezer III 360', 'cooler');

        $this->assertSame('air', $air['meta']['kind']);
        $this->assertSame('aio', $aio['meta']['kind']);
        $this->assertNotSame(
            json_encode($air['meshes']),
            json_encode(array_merge(...array_column($aio['groups'], 'meshes')))
        );
    }
}