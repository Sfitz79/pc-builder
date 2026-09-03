<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompatibilityRule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'category',
        'rule_type',
        'conditions',
        'active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'active' => 'boolean',
    ];

    public function evaluate(array $selection): bool
    {
        return match ($this->rule_type) {
            'socket_match' => $this->evaluateSocketMatch($selection),
            'wattage_sufficient' => $this->evaluateWattage($selection),
            'memory_supported' => $this->evaluateMemory($selection),
            'clearance_check' => $this->evaluateClearance($selection),
            'cooler_mount' => $this->evaluateCoolerMount($selection),
            'form_factor_match' => $this->evaluateFormFactor($selection),
            'm2_compatibility' => $this->evaluateM2Compatibility($selection),
            'pcie_slot' => $this->evaluatePcieSlot($selection),
            'ram_speed_optimal' => $this->evaluateRamSpeedOptimal($selection),
            'gpu_power_connector' => $this->evaluateGpuPowerConnector($selection),
            default => true,
        };
    }

    protected function evaluateSocketMatch(array $selection): bool
    {
        $cpu = $selection['cpu'] ?? null;
        $board = $selection['motherboard'] ?? null;

        return $cpu === null || $board === null || $cpu['socket'] === $board['socket'];
    }

    protected function evaluateWattage(array $selection): bool
    {
        $gpu = $selection['gpu'] ?? null;
        $psu = $selection['psu'] ?? null;
        $cpu = $selection['cpu'] ?? null;

        if ($gpu === null || $psu === null) {
            return true;
        }

        $overhead = (int) ($this->conditions['overhead'] ?? 200);
        $gpuWattage = (int) ($gpu['wattage'] ?? 0);
        $cpuWattage = (int) ($cpu['wattage'] ?? 0);

        return (int) $psu['wattage'] >= ($gpuWattage + $cpuWattage + $overhead);
    }

    protected function evaluateMemory(array $selection): bool
    {
        $ram = $selection['ram'] ?? null;
        $board = $selection['motherboard'] ?? null;

        if ($ram === null || $board === null) {
            return true;
        }

        $ramSpeed = strtoupper((string) ($ram['specs']['speed'] ?? $ram['speed'] ?? $this->arrayValue($ram, 'speed')));
        $boardSocket = strtoupper((string) ($board['socket'] ?? $this->arrayValue($board, 'socket')));

        if ($ramSpeed === '') {
            return true;
        }

        // Explicit DDR4/DDR5 marker in the speed string beats every guess.
        if (str_contains($ramSpeed, 'DDR5')) {
            $isDDR5 = true;
        } elseif (str_contains($ramSpeed, 'DDR4')) {
            $isDDR5 = false;
        } else {
            // Elsewhere the speed is opaque (e.g. "6000 MT/s"). DDR5 platforms
            // run >= 3600 MT/s; anything under that (DDR4 speeds) is DDR4.
            $speed = $this->firstNumber($ramSpeed);

            if ($speed === null) {
                return true;
            }

            $isDDR5 = $speed >= 3600;
        }

        if ($boardSocket === 'AM5' || $boardSocket === 'LGA1851') {
            return $isDDR5;
        }

        if ($boardSocket === 'AM4' || $boardSocket === 'LGA1200' || $boardSocket === 'LGA1700') {
            return ! $isDDR5;
        }

        return true;
    }

    protected function evaluateClearance(array $selection): bool
    {
        $gpu = $selection['gpu'] ?? null;
        $case = $selection['case'] ?? null;

        if ($gpu === null || $case === null) {
            return true;
        }

        $gpuLength = (int) ($gpu['specs']['length'] ?? $gpu['length'] ?? 0);
        $caseMaxLength = (int) ($case['specs']['max_gpu_length'] ?? $case['max_gpu_length'] ?? 0);

        return $gpuLength === 0 || $caseMaxLength === 0 || $gpuLength <= $caseMaxLength;
    }

    protected function evaluateCoolerMount(array $selection): bool
    {
        $cooler = $selection['cooler'] ?? null;
        $cpu = $selection['cpu'] ?? null;

        if ($cooler === null || $cpu === null) {
            return true;
        }

        $supportedSockets = $cooler['specs']['supported_sockets'] ?? [];
        $cpuSocket = $cpu['socket'] ?? '';

        if (empty($supportedSockets) || $cpuSocket === '') {
            return true;
        }

        return in_array($cpuSocket, $supportedSockets);
    }

    protected function evaluateFormFactor(array $selection): bool
    {
        $board = $selection['motherboard'] ?? null;
        $case = $selection['case'] ?? null;

        if ($board === null || $case === null) {
            return true;
        }

        $boardFormFactor = $board['specs']['form_factor'] ?? $board['form_factor'] ?? '';
        $caseFormFactors = $case['specs']['supported_form_factors'] ?? [];

        if ($boardFormFactor === '' || empty($caseFormFactors)) {
            return true;
        }

        return in_array($boardFormFactor, $caseFormFactors);
    }

    protected function evaluateM2Compatibility(array $selection): bool
    {
        $ssd = $selection['storage'] ?? null;
        $board = $selection['motherboard'] ?? null;

        if ($ssd === null || $board === null) {
            return true;
        }

        $ssdType = $ssd['specs']['type'] ?? '';
        $boardM2Slots = $board['specs']['m2_slots'] ?? [];

        if ($ssdType === '' || empty($boardM2Slots)) {
            return true;
        }

        // Check if any M.2 slot supports the SSD type
        foreach ($boardM2Slots as $slot) {
            if (str_contains($ssdType, 'NVMe') && str_contains($slot, 'PCIe')) {
                return true;
            }
            if (str_contains($ssdType, 'SATA') && str_contains($slot, 'SATA')) {
                return true;
            }
        }

        return false;
    }

    protected function evaluatePcieSlot(array $selection): bool
    {
        $gpu = $selection['gpu'] ?? null;
        $board = $selection['motherboard'] ?? null;

        if ($gpu === null || $board === null) {
            return true;
        }

        $gpuInterface = $gpu['specs']['interface'] ?? '';
        $boardPcieSlots = $board['specs']['pcie_slots'] ?? [];

        if ($gpuInterface === '' || empty($boardPcieSlots)) {
            return true;
        }

        // Check if there's at least one PCIe x16 slot
        foreach ($boardPcieSlots as $slot) {
            if (str_contains($slot, 'x16')) {
                return true;
            }
        }

        return false;
    }

    protected function evaluateRamSpeedOptimal(array $selection): bool
    {
        $ram = $selection['ram'] ?? null;
        $board = $selection['motherboard'] ?? null;

        if ($ram === null || $board === null) {
            return true;
        }

        $optimalSpeed = (int) ($this->conditions['optimal_speed'] ?? 0);
        $platform = $this->conditions['platform'] ?? '';
        $boardSocket = strtoupper((string) ($board['socket'] ?? ''));

        if ($optimalSpeed === 0 || $platform === '' || $boardSocket !== $platform) {
            return true;
        }

        $ramSpeed = (int) ($ram['specs']['speed'] ?? $ram['speed'] ?? $this->firstNumber($this->arrayValue($ram, 'speed') ?? ''));

        if ($ramSpeed === 0) {
            return true;
        }

        // Warn if speed is significantly different from optimal (within 20% tolerance)
        $tolerance = $optimalSpeed * 0.2;

        return abs($ramSpeed - $optimalSpeed) <= $tolerance;
    }

    protected function evaluateGpuPowerConnector(array $selection): bool
    {
        $gpu = $selection['gpu'] ?? null;
        $psu = $selection['psu'] ?? null;

        if ($gpu === null || $psu === null) {
            return true;
        }

        $gpuPowerConnector = $gpu['specs']['power_connector'] ?? '';
        $psuConnectors = $psu['specs']['connectors'] ?? [];

        if ($gpuPowerConnector === '' || empty($psuConnectors)) {
            return true;
        }

        // Check if PSU has the required connector or an adapter
        if (str_contains($gpuPowerConnector, '12VHPWR') || str_contains($gpuPowerConnector, '16-pin')) {
            return in_array('12VHPWR', $psuConnectors) || in_array('adapter_12VHPWR', $psuConnectors);
        }

        if (str_contains($gpuPowerConnector, '8-pin')) {
            return in_array('8-pin', $psuConnectors) || in_array('6+2-pin', $psuConnectors);
        }

        return true;
    }

    /**
     * Read a dotted/array key from a structured value regardless of whether the
     * selection item was a full Eloquent row or a flattened payload.
     */
    protected function arrayValue(array $item, string $key): mixed
    {
        return $item[$key] ?? null;
    }

    protected function firstNumber(string $value): ?int
    {
        return preg_match('/\d+/', $value, $m) === 1 ? (int) $m[0] : null;
    }
}
