<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed the compatibility rules the engine can already evaluate.
 *
 * MEASURED 2026-10-13, and this seeder exists because of what the measurement found.
 *
 * THE GAP
 * CompatibilityRule::evaluate() implements NINE rule types. Production contained exactly ONE
 * row: "Motherboard fits case" (form_factor_match). CompatibilityService::summary() reports
 * five customer-facing checks, and four of them had no rule behind them at all, so they
 * returned their default:
 *
 *     'cpuMotherboard' => $results->get('cpu_motherboard', true),
 *     'ramSupported'    => $results->get('memory',        true),
 *     'powerEnough'     => $results->get('power',         true),
 *     'gpuClearance'    => $results->get('clearance',     true),
 *
 * A missing rule reports PASS. So four of the five checks on our compatibility panel were
 * displaying a default rather than a verification, and nothing in the UI said so.
 *
 * WHY IT IS SAFE TO SEED
 * 1. The evaluation code already existed and is unchanged. Seeding activates it; it does not
 *    introduce untested behaviour.
 * 2. scripts/genie-probe-compatibility-rules.php evaluated every rule against all 5 real
 *    production builds BEFORE this seeder existed: 0 failures, 0 errors.
 * 3. scripts/genie-probe-compatibility-detection.php proves each rule can actually go RED on
 *    a deliberately broken build. An earlier run of that probe reported three failures and
 *    looked alarming - it turned out the PROBE was wrong, feeding specs.tdp and
 *    length_mm where the rules read top-level wattage and specs.length. The rules were fine.
 *    A check that cannot fail is not a check, so this had to be proven rather than assumed.
 *
 * IDEMPOTENT: keyed on (category, rule_type). Safe to re-run; it will not duplicate.
 */
class CompatibilityRuleSeeder extends Seeder
{
    /**
     * category => the key CompatibilityService::summary() looks up
     * rule_type => the branch in CompatibilityRule::evaluate()
     */
    private const RULES = [
        ['category' => 'cpu_motherboard', 'rule_type' => 'socket_match', 'name' => 'CPU socket matches motherboard socket'],
        ['category' => 'memory', 'rule_type' => 'memory_supported', 'name' => 'Memory generation supported by the motherboard'],
        ['category' => 'power', 'rule_type' => 'wattage_sufficient', 'name' => 'Power supply has enough headroom for CPU and graphics card'],
        ['category' => 'clearance', 'rule_type' => 'clearance_check', 'name' => 'Graphics card physically fits the case'],
        ['category' => 'cooler', 'rule_type' => 'cooler_mount', 'name' => 'CPU cooler mounting matches the CPU socket'],
        ['category' => 'storage', 'rule_type' => 'm2_compatibility', 'name' => 'M.2 storage supported by the motherboard'],
        ['category' => 'pcie', 'rule_type' => 'pcie_slot', 'name' => 'Graphics card has a usable PCIe slot'],
        ['category' => 'ram_speed', 'rule_type' => 'ram_speed_optimal', 'name' => 'Memory speed is a sensible match for the platform'],
        ['category' => 'gpu_power', 'rule_type' => 'gpu_power_connector', 'name' => 'Power supply has the connectors the graphics card needs'],
    ];

    public function run(): void
    {
        $inserted = 0;
        $skipped = 0;

        foreach (self::RULES as $rule) {
            $exists = DB::table('compatibility_rules')
                ->where('category', $rule['category'])
                ->where('rule_type', $rule['rule_type'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DB::table('compatibility_rules')->insert([
                'name' => $rule['name'],
                'description' => null,
                'category' => $rule['category'],
                'rule_type' => $rule['rule_type'],
                // The form_factor row shipped with {"fail_closed": true}; every rule that can
                // block a build is seeded the same way, so a rule with unreadable data does
                // not quietly pass on the strength of a missing field alone.
                'conditions' => json_encode(['fail_closed' => true]),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        $this->command?->info("compatibility rules: {$inserted} inserted, {$skipped} already present.");
    }
}
