<?php

namespace App\Services;

class CompatibilityCheckerService
{
    /**
     * Check compatibility between all selected parts
     */
    public function checkCompatibility(array $parts): array
    {
        $warnings = [];
        $errors = [];

        // CPU + Motherboard socket compatibility
        if (!empty($parts['cpu']) && !empty($parts['motherboard'])) {
            $cpuSocket = $parts['cpu']['socket'] ?? null;
            $mbSocket = $parts['motherboard']['socket'] ?? null;

            if ($cpuSocket && $mbSocket && $cpuSocket !== $mbSocket) {
                $errors[] = "CPU socket ({$cpuSocket}) is incompatible with motherboard socket ({$mbSocket})";
            }
        }

        // RAM type compatibility (DDR4 vs DDR5)
        if (!empty($parts['motherboard']) && !empty($parts['ram'])) {
            $mbRamType = $parts['motherboard']['ram_type'] ?? null;
            $ramType = $parts['ram']['type'] ?? null;

            if ($mbRamType && $ramType && $mbRamType !== $ramType) {
                $errors[] = "RAM type ({$ramType}) is incompatible with motherboard ({$mbRamType})";
            }
        }

        // RAM speed compatibility
        if (!empty($parts['motherboard']) && !empty($parts['ram'])) {
            $maxRamSpeed = $parts['motherboard']['max_ram_speed'] ?? null;
            $ramSpeed = $parts['ram']['speed'] ?? null;

            if ($maxRamSpeed && $ramSpeed && $ramSpeed > $maxRamSpeed) {
                $warnings[] = "RAM speed ({$ramSpeed}MHz) exceeds motherboard maximum ({$maxRamSpeed}MHz)";
            }
        }

        // RAM capacity compatibility
        if (!empty($parts['motherboard']) && !empty($parts['ram'])) {
            $maxRam = $parts['motherboard']['max_ram'] ?? null;
            $ramCapacity = $parts['ram']['capacity'] ?? null;

            if ($maxRam && $ramCapacity && $ramCapacity > $maxRam) {
                $errors[] = "RAM capacity ({$ramCapacity}GB) exceeds motherboard maximum ({$maxRam}GB)";
            }
        }

        // Power supply wattage check
        $totalPower = $this->estimatePowerConsumption($parts);
        if (!empty($parts['psu'])) {
            $psuWattage = $parts['psu']['wattage'] ?? 0;
            if ($psuWattage > 0 && $totalPower > $psuWattage * 0.8) {
                $warnings[] = "Estimated power usage ({$totalPower}W) may exceed PSU recommended capacity ({$psuWattage}W at 80%)";
            }
        }

        // ------------------------------------------------------------------
        // PHYSICAL FIT - fail-closed via FitVerification.
        //
        // This block previously read:
        //     $maxGpuLength = $parts['case']['max_gpu_length'] ?? null;
        //     if ($maxGpuLength && $gpuLength && $gpuLength > $maxGpuLength) {...}
        // The comparison only runs when BOTH values are truthy, so a missing
        // dimension meant the check silently did nothing and `compatible` came
        // back true. Measured on production Neon on 2026-10-05: gpu.length 306/306
        // missing, case.max_gpu_length 399/399 missing, cooler.height 372/372
        // missing, case.form_factor 399/399 missing. All three checks were dead and
        // every build was being declared compatible.
        //
        // FitVerification distinguishes pass / fail / UNVERIFIED so an unchecked
        // dimension can never be reported as a checked one.
        // ------------------------------------------------------------------
        $fit = FitVerification::assess($parts);

        foreach ($fit['checks'] as $check) {
            if ($check['state'] === FitVerification::FAIL) {
                $errors[] = $check['detail'];
            } elseif ($check['state'] === FitVerification::UNVERIFIED) {
                $warnings[] = 'Clearance not verified - ' . $check['detail'];
            }
        }

        return [
            'compatible' => empty($errors),
            // Explicitly separate from 'compatible'. True means every physical fit
            // check actually ran. A caller that only reads 'compatible' is still
            // correct - it is just not being told the clearance was proven.
            'fit_verified' => $fit['verified'],
            'fit_checks' => $fit['checks'],
            'errors' => $errors,
            'warnings' => $warnings,
            'estimated_power' => $totalPower,
        ];
    }

    /**
     * Estimate total power consumption
     */
    protected function estimatePowerConsumption(array $parts): int
    {
        $tdp = [
            'cpu' => 125,
            'gpu' => 250,
            'ram' => 10,
            'storage' => 10,
            'motherboard' => 50,
            'cooler' => 5,
        ];

        $total = 50; // Base system power

        foreach ($tdp as $component => $defaultWattage) {
            if (!empty($parts[$component])) {
                $componentTdp = $parts[$component]['tdp'] ?? $parts[$component]['wattage'] ?? $defaultWattage;
                $total += (int) $componentTdp;
            }
        }

        return $total;
    }

    /**
     * Get compatible case form factors
     */
    protected function getCaseCompatibility(string $caseFormFactor): array
    {
        $compatibility = [
            'ATX' => ['ATX', 'Micro-ATX', 'Mini-ITX'],
            'Micro-ATX' => ['Micro-ATX', 'Mini-ITX'],
            'Mini-ITX' => ['Mini-ITX'],
            'E-ATX' => ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'],
        ];

        return $compatibility[$caseFormFactor] ?? [];
    }

    /**
     * Generate build summary
     */
    public function generateSummary(array $parts): array
    {
        $compatibility = $this->checkCompatibility($parts);

        $totalPrice = 0;
        $components = [];

        foreach ($parts as $type => $part) {
            if (!empty($part)) {
                $price = (float) ($part['price'] ?? 0);
                $totalPrice += $price;
                $components[$type] = [
                    'name' => $part['name'] ?? 'Unknown',
                    'price' => $price,
                    'specs' => $this->getComponentSpecs($type, $part),
                ];
            }
        }

        return [
            'total_price' => round($totalPrice, 2),
            'components' => $components,
            'compatibility' => $compatibility,
            'estimated_power' => $compatibility['estimated_power'],
        ];
    }

    /**
     * Get component specifications
     */
    protected function getComponentSpecs(string $type, array $part): array
    {
        $specs = [];

        switch ($type) {
            case 'cpu':
                $specs = [
                    'Cores' => $part['cores'] ?? 'N/A',
                    'Threads' => $part['threads'] ?? 'N/A',
                    'Base Clock' => $part['base_clock'] ?? 'N/A',
                    'Boost Clock' => $part['boost_clock'] ?? 'N/A',
                    'Socket' => $part['socket'] ?? 'N/A',
                    'TDP' => ($part['tdp'] ?? 'N/A') . 'W',
                ];
                break;
            case 'gpu':
                $specs = [
                    'VRAM' => $part['vram'] ?? 'N/A',
                    'Boost Clock' => $part['boost_clock'] ?? 'N/A',
                    'TDP' => ($part['tdp'] ?? 'N/A') . 'W',
                    'Length' => ($part['length'] ?? 'N/A') . 'mm',
                ];
                break;
            case 'motherboard':
                $specs = [
                    'Socket' => $part['socket'] ?? 'N/A',
                    'Form Factor' => $part['form_factor'] ?? 'N/A',
                    'RAM Type' => $part['ram_type'] ?? 'N/A',
                    'Max RAM' => ($part['max_ram'] ?? 'N/A') . 'GB',
                    'PCIe Slots' => $part['pcie_slots'] ?? 'N/A',
                ];
                break;
            case 'ram':
                $specs = [
                    'Capacity' => ($part['capacity'] ?? 'N/A') . 'GB',
                    'Speed' => ($part['speed'] ?? 'N/A') . 'MHz',
                    'Type' => $part['type'] ?? 'N/A',
                    'Modules' => $part['modules'] ?? 'N/A',
                ];
                break;
            case 'storage':
                $specs = [
                    'Capacity' => $part['capacity'] ?? 'N/A',
                    'Type' => $part['type'] ?? 'N/A',
                    'Interface' => $part['interface'] ?? 'N/A',
                    'Read Speed' => ($part['read_speed'] ?? 'N/A') . ' MB/s',
                ];
                break;
            case 'psu':
                $specs = [
                    'Wattage' => ($part['wattage'] ?? 'N/A') . 'W',
                    'Efficiency' => $part['efficiency'] ?? 'N/A',
                    'Modular' => $part['modular'] ?? 'N/A',
                ];
                break;
        }

        return $specs;
    }
}
