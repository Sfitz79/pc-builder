<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RULES = [
        ['category' => 'cpu_motherboard', 'rule_type' => 'socket_match', 'name' => 'CPU socket matches motherboard socket'],
        ['category' => 'memory', 'rule_type' => 'memory_supported', 'name' => 'Memory generation supported by the motherboard'],
        ['category' => 'power', 'rule_type' => 'wattage_sufficient', 'name' => 'Power supply has enough headroom for CPU and graphics card'],
        ['category' => 'clearance', 'rule_type' => 'clearance_check', 'name' => 'Graphics card physically fits the case'],
        ['category' => 'form_factor', 'rule_type' => 'form_factor_match', 'name' => 'Motherboard fits case'],
        ['category' => 'cooler', 'rule_type' => 'cooler_mount', 'name' => 'CPU cooler mounting matches the CPU socket'],
        ['category' => 'storage', 'rule_type' => 'm2_compatibility', 'name' => 'M.2 storage supported by the motherboard'],
        ['category' => 'pcie', 'rule_type' => 'pcie_slot', 'name' => 'Graphics card has a usable PCIe slot'],
        ['category' => 'ram_speed', 'rule_type' => 'ram_speed_optimal', 'name' => 'Memory speed is a sensible match for the platform'],
        ['category' => 'gpu_power', 'rule_type' => 'gpu_power_connector', 'name' => 'Power supply has the connectors the graphics card needs'],
    ];

    public function up(): void
    {
        foreach (self::RULES as $rule) {
            $exists = DB::table('compatibility_rules')
                ->where('category', $rule['category'])
                ->where('rule_type', $rule['rule_type'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('compatibility_rules')->insert([
                ...$rule,
                'description' => null,
                'conditions' => json_encode(['fail_closed' => true], JSON_THROW_ON_ERROR),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep compatibility rules on rollback rather than risking deletion of
        // operator-edited configuration.
    }
};
