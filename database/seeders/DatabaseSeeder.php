<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            ManufacturerSeeder::class,
            ComponentSeeder::class,
            CompatibilityRuleSeeder::class,
            BenchmarkSeeder::class,
        ]);
    }
}
