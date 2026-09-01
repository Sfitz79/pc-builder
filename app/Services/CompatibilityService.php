<?php

namespace App\Services;

use App\Models\CompatibilityRule;
use Illuminate\Support\Collection;

class CompatibilityService
{
    /**
     * Run every active compatibility rule against a selection.
     *
     * @param  array<string, array<string, mixed>|null>  $selection  keyed by category slug (cpu, gpu, psu, ...)
     * @return Collection<int, array{rule: CompatibilityRule, pass: bool, category: string}>
     */
    public function check(array $selection): Collection
    {
        $results = CompatibilityRule::query()
            ->where('active', true)
            ->get()
            ->map(fn (CompatibilityRule $rule) => [
                'rule' => $rule,
                'category' => $rule->category,
                'pass' => $rule->evaluate($selection),
            ]);

        return $results;
    }

    public function isFullyCompatible(array $selection): bool
    {
        return $this->check($selection)->every(fn (array $result) => $result['pass']);
    }

    /**
     * Summarise into the same shape the Alpine builder state expects.
     *
     * @return array{cpuMotherboard: bool, ramSupported: bool, powerEnough: bool, gpuClearance: bool}
     */
    public function summary(array $selection): array
    {
        $results = $this->check($selection)
            ->groupBy('category')
            ->map(fn (Collection $group) => $group->every(fn (array $result) => $result['pass']));

        return [
            'cpuMotherboard' => $results->get('cpu_motherboard', true),
            'ramSupported' => $results->get('memory', true),
            'powerEnough' => $results->get('power', true),
            'gpuClearance' => $results->get('clearance', true),
        ];
    }

    /**
     * Score a build from 0-100 based on passing rules.
     */
    public function score(array $selection): int
    {
        $results = $this->check($selection);

        if ($results->isEmpty()) {
            return 100;
        }

        return (int) round(
            $results->where('pass', true)->count() / $results->count() * 100
        );
    }
}
