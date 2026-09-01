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

        if ($gpu === null || $psu === null) {
            return true;
        }

        $overhead = (int) ($this->conditions['overhead'] ?? 200);

        return (int) $psu['wattage'] >= ((int) $gpu['wattage']) + $overhead;
    }

    protected function evaluateMemory(array $selection): bool
    {
        $ram = $selection['ram'] ?? null;

        return $ram === null || ($ram['supported'] ?? true) === true;
    }

    protected function evaluateClearance(array $selection): bool
    {
        $gpu = $selection['gpu'] ?? null;
        $case = $selection['case'] ?? null;

        if ($gpu === null || $case === null) {
            return true;
        }

        $gpuLength = (int) ($gpu['length'] ?? 0);
        $caseMaxLength = (int) ($case['max_gpu_length'] ?? 0);

        return $gpuLength === 0 || $caseMaxLength === 0 || $gpuLength <= $caseMaxLength;
    }
}
