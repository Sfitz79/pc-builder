<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Component;
use Illuminate\Database\Seeder;

class ComponentSeeder extends Seeder
{
    public function run(): void
    {
        $components = [
            // CPUs
            ['name' => 'AMD Ryzen 9700X', 'slug' => 'amd-ryzen-9700x', 'manufacturer' => 'amd', 'category' => 'cpu', 'price' => 329.00, 'socket' => 'AM5', 'wattage' => 65, 'stock' => 12, 'specs' => ['cores' => 8, 'threads' => 16]],
            ['name' => 'AMD Ryzen 7800X3D', 'slug' => 'amd-ryzen-7800x3d', 'manufacturer' => 'amd', 'category' => 'cpu', 'price' => 389.00, 'socket' => 'AM5', 'wattage' => 120, 'stock' => 8, 'specs' => ['cores' => 8, 'threads' => 16]],
            ['name' => 'Intel Core i5-14600K', 'slug' => 'intel-core-i5-14600k', 'manufacturer' => 'intel', 'category' => 'cpu', 'price' => 279.00, 'socket' => 'LGA1700', 'wattage' => 181, 'stock' => 15, 'specs' => ['cores' => 14, 'threads' => 20]],
            // Motherboards
            ['name' => 'ASUS B650-A Gaming', 'slug' => 'asus-b650-a-gaming', 'manufacturer' => 'asus', 'category' => 'motherboard', 'price' => 189.00, 'socket' => 'AM5', 'stock' => 10],
            ['name' => 'MSI X870E Tomahawk', 'slug' => 'msi-x870e-tomahawk', 'manufacturer' => 'msi', 'category' => 'motherboard', 'price' => 349.00, 'socket' => 'AM5', 'stock' => 6],
            ['name' => 'Gigabyte Z790 Aorus', 'slug' => 'gigabyte-z790-aorus', 'manufacturer' => 'gigabyte', 'category' => 'motherboard', 'price' => 299.00, 'socket' => 'LGA1700', 'stock' => 7],
            // GPUs
            ['name' => 'RTX 5070 Ti', 'slug' => 'rtx-5070-ti', 'manufacturer' => 'nvidia', 'category' => 'gpu', 'price' => 799.00, 'wattage' => 300, 'stock' => 9, 'specs' => ['memory' => '16GB GDDR7']],
            ['name' => 'RTX 5080', 'slug' => 'rtx-5080', 'manufacturer' => 'nvidia', 'category' => 'gpu', 'price' => 1099.00, 'wattage' => 360, 'stock' => 5, 'specs' => ['memory' => '16GB GDDR7']],
            ['name' => 'RTX 5090', 'slug' => 'rtx-5090', 'manufacturer' => 'nvidia', 'category' => 'gpu', 'price' => 1999.00, 'wattage' => 575, 'stock' => 2, 'specs' => ['memory' => '32GB GDDR7']],
            ['name' => 'RX 9070 XT', 'slug' => 'rx-9070-xt', 'manufacturer' => 'amd', 'category' => 'gpu', 'price' => 549.00, 'wattage' => 304, 'stock' => 11, 'specs' => ['memory' => '16GB GDDR6']],
            // RAM
            ['name' => '32GB DDR5 6000', 'slug' => '32gb-ddr5-6000', 'manufacturer' => 'corsair', 'category' => 'ram', 'price' => 119.00, 'stock' => 20, 'specs' => ['capacity' => '32GB', 'speed' => '6000 MT/s']],
            ['name' => '64GB DDR5 6000', 'slug' => '64gb-ddr5-6000', 'manufacturer' => 'corsair', 'category' => 'ram', 'price' => 219.00, 'stock' => 14, 'specs' => ['capacity' => '64GB', 'speed' => '6000 MT/s']],
            // Storage
            ['name' => '1TB NVMe Gen4', 'slug' => '1tb-nvme-gen4', 'manufacturer' => 'samsung', 'category' => 'storage', 'price' => 89.00, 'stock' => 25],
            ['name' => '2TB NVMe Gen4', 'slug' => '2tb-nvme-gen4', 'manufacturer' => 'samsung', 'category' => 'storage', 'price' => 139.00, 'stock' => 22],
            ['name' => '4TB NVMe Gen4', 'slug' => '4tb-nvme-gen4', 'manufacturer' => 'samsung', 'category' => 'storage', 'price' => 249.00, 'stock' => 10],
            // PSUs
            ['name' => '650W 80+ Gold', 'slug' => '650w-80-plus-gold', 'manufacturer' => 'corsair', 'category' => 'psu', 'price' => 99.00, 'wattage' => 650, 'stock' => 18],
            ['name' => '850W 80+ Gold', 'slug' => '850w-80-plus-gold', 'manufacturer' => 'corsair', 'category' => 'psu', 'price' => 129.00, 'wattage' => 850, 'stock' => 16],
            ['name' => '1000W 80+ Gold', 'slug' => '1000w-80-plus-gold', 'manufacturer' => 'corsair', 'category' => 'psu', 'price' => 159.00, 'wattage' => 1000, 'stock' => 12],
            // Cases
            ['name' => 'Lian Li O11 Vision', 'slug' => 'lian-li-o11-vision', 'manufacturer' => 'lian-li', 'category' => 'case', 'price' => 149.00, 'stock' => 9],
            ['name' => 'NZXT H6 Flow RGB', 'slug' => 'nzxt-h6-flow-rgb', 'manufacturer' => 'nzxt', 'category' => 'case', 'price' => 129.00, 'stock' => 13],
            ['name' => 'Fractal North', 'slug' => 'fractal-north', 'manufacturer' => 'fractal-design', 'category' => 'case', 'price' => 119.00, 'stock' => 8],
        ];

        foreach ($components as $component) {
            Component::updateOrCreate(
                ['slug' => $component['slug']],
                [
                    'manufacturer_id' => \App\Models\Manufacturer::where('slug', $component['manufacturer'])->firstOrFail()->id,
                    'category_id' => Category::where('slug', $component['category'])->firstOrFail()->id,
                    'name' => $component['name'],
                    'sku' => 'PCTG-' . strtoupper(str_replace('-', '', $component['slug'])),
                    'description' => $component['name'] . ' — sourced from trusted UK retailers.',
                    'price' => $component['price'],
                    'socket' => $component['socket'] ?? null,
                    'wattage' => $component['wattage'] ?? null,
                    'stock' => $component['stock'],
                    'active' => true,
                    'specs' => $component['specs'] ?? null,
                ]
            );
        }
    }
}
