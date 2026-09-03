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
                'description' => 'The motherboard socket must match the CPU socket. AMD CPUs require AM4 or AM5 sockets; Intel CPUs require LGA1700 or LGA1851 sockets.',
                'category' => 'cpu_motherboard',
                'rule_type' => 'socket_match',
                'conditions' => [],
            ],
            [
                'name' => 'PSU capacity for GPU draw',
                'description' => 'PSU wattage must cover GPU TDP plus CPU TDP plus 200W overhead for other components (RAM, storage, fans, etc.).',
                'category' => 'power',
                'rule_type' => 'wattage_sufficient',
                'conditions' => ['overhead' => 200],
            ],
            [
                'name' => 'RAM platform support',
                'description' => 'Memory generation must be supported by the platform. AM5 and LGA1851 require DDR5; AM4 and LGA1700 support DDR4 (some LGA1700 boards support DDR5).',
                'category' => 'memory',
                'rule_type' => 'memory_supported',
                'conditions' => [],
            ],
            [
                'name' => 'Case GPU clearance',
                'description' => 'Case must physically fit the selected GPU. GPU length must not exceed case maximum GPU clearance.',
                'category' => 'clearance',
                'rule_type' => 'clearance_check',
                'conditions' => [],
            ],
            [
                'name' => 'CPU cooler mounting compatibility',
                'description' => 'CPU cooler must support the CPU socket. AM4 and AM5 share the same mounting holes, but Intel sockets require different brackets.',
                'category' => 'cooler',
                'rule_type' => 'cooler_mount',
                'conditions' => [],
            ],
            [
                'name' => 'Motherboard form factor fit',
                'description' => 'Motherboard form factor (ATX, Micro-ATX, Mini-ITX) must fit within the case. ATX boards require ATX-compatible cases.',
                'category' => 'form_factor',
                'rule_type' => 'form_factor_match',
                'conditions' => [],
            ],
            [
                'name' => 'M.2 storage compatibility',
                'description' => 'M.2 SSD generation must be supported by the motherboard. Gen4 NVMe drives work in Gen4 and Gen5 slots; Gen5 drives require Gen5 slots for full speed.',
                'category' => 'storage',
                'rule_type' => 'm2_compatibility',
                'conditions' => [],
            ],
            [
                'name' => 'PCIe slot compatibility',
                'description' => 'GPU must be installed in a PCIe x16 slot. Most modern motherboards have at least one PCIe x16 slot for the primary GPU.',
                'category' => 'expansion',
                'rule_type' => 'pcie_slot',
                'conditions' => [],
            ],
            [
                'name' => 'RAM speed platform optimization',
                'description' => 'DDR5-6000 MT/s is the optimal speed for AMD AM5 (1:1 ratio with Infinity Fabric). Higher speeds may cause instability.',
                'category' => 'memory',
                'rule_type' => 'ram_speed_optimal',
                'conditions' => ['optimal_speed' => 6000, 'platform' => 'AM5'],
            ],
            [
                'name' => 'GPU power connector compatibility',
                'description' => 'High-end GPUs (RTX 5080, 5090) require 12VHPWR connectors. Ensure PSU has appropriate cables or use adapters.',
                'category' => 'power',
                'rule_type' => 'gpu_power_connector',
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
