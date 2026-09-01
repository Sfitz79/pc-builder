<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ManufacturerSeeder extends Seeder
{
    public function run(): void
    {
        $manufacturers = [
            ['name' => 'AMD', 'slug' => 'amd', 'website' => 'https://www.amd.com'],
            ['name' => 'Intel', 'slug' => 'intel', 'website' => 'https://www.intel.com'],
            ['name' => 'NVIDIA', 'slug' => 'nvidia', 'website' => 'https://www.nvidia.com'],
            ['name' => 'ASUS', 'slug' => 'asus', 'website' => 'https://www.asus.com'],
            ['name' => 'MSI', 'slug' => 'msi', 'website' => 'https://www.msi.com'],
            ['name' => 'Gigabyte', 'slug' => 'gigabyte', 'website' => 'https://www.gigabyte.com'],
            ['name' => 'Samsung', 'slug' => 'samsung', 'website' => 'https://www.samsung.com'],
            ['name' => 'Corsair', 'slug' => 'corsair', 'website' => 'https://www.corsair.com'],
            ['name' => 'Lian Li', 'slug' => 'lian-li', 'website' => 'https://lian-li.com'],
            ['name' => 'NZXT', 'slug' => 'nzxt', 'website' => 'https://www.nzxt.com'],
            ['name' => 'Fractal Design', 'slug' => 'fractal-design', 'website' => 'https://www.fractal-design.com'],
        ];

        DB::table('manufacturers')->insert(
            array_map(
                fn (array $m): array => $m + ['active' => true, 'created_at' => now(), 'updated_at' => now()],
                $manufacturers
            )
        );
    }
}
