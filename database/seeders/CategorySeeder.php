<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'CPU', 'slug' => 'cpu', 'sort_order' => 10],
            ['name' => 'Motherboard', 'slug' => 'motherboard', 'sort_order' => 20],
            ['name' => 'GPU', 'slug' => 'gpu', 'sort_order' => 30],
            ['name' => 'RAM', 'slug' => 'ram', 'sort_order' => 40],
            ['name' => 'Storage', 'slug' => 'storage', 'sort_order' => 50],
            ['name' => 'PSU', 'slug' => 'psu', 'sort_order' => 60],
            ['name' => 'Case', 'slug' => 'case', 'sort_order' => 70],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['active' => true]
            );
        }
    }
}
