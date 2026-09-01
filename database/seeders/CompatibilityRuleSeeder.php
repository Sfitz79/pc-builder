<?php

namespace Database\Seeders;

use App\Models\CompatibilityRule;
use Illuminate\Database\Seeder;

class CompatibilityRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'name' => 'CPU / Motherboard socket match',
                'description' => 'The motherboard socket must match the CPU socket.',
                'category' => 'cpu_motherboard',
                'rule_type' => 'socket_match',
                'conditions' => [],
            ],
            [
                'name' => 'PSU capacity for GPU draw',
                'description' => 'PSU wattage must cover GPU draw plus system overhead.',
                'category' => 'power',
                'rule_type' => 'wattage_sufficient',
                'conditions' => ['overhead' => 200],
            ],
            [
                'name' => 'RAM platform support',
                'description' => 'Memory generation must be supported by the platform.',
                'category' => 'memory',
                'rule_type' => 'memory_supported',
                'conditions' => [],
            ],
            [
                'name' => 'Case GPU clearance',
                'description' => 'Case must physically fit the selected GPU.',
                'category' => 'clearance',
                'rule_type' => 'clearance_check',
                'conditions' => [],
            ],
        ];

        foreach ($rules as $rule) {
            CompatibilityRule::updateOrCreate(
                ['name' => $rule['name']],
                $rule + ['active' => true]
            );
        }
    }
}
