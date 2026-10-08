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
     * The four ORIGINAL flags are returned with their original defaults. Every
     * one of them defaults to `true` when its category is absent from the
     * results, which is why a missing rule row silently reads as "compatible".
     * formFactorFits deliberately does NOT follow that pattern: an absent
     * category would mean the form_factor_match rule row is missing, and
     * reporting `true` there is precisely the fail-open this whole path exists
     * to prevent. It defaults to `false` and is commented as such, because the
     * safe error is a visible warning the customer can clear by changing a part,
     * not a green tick on a check that never ran.
     *
     * @return array{cpuMotherboard: bool, ramSupported: bool, powerEnough: bool, gpuClearance: bool, formFactorFits: bool}
     */
    public function summary(array $selection): array
    {
        $results = $this->check($selection)
            ->groupBy('category')
            ->map(fn (Collection $group) => $group->every(fn (array $result) => (bool) $result['pass']));

        // formFactorFits means "the fit is verified AND correct", which is not
        // the same as "the rule passed".
        //
        // evaluateFormFactor() returns TRUE when either part is absent, because
        // a rule must not block a half-finished build - every other rule in
        // this table behaves that way and changing it would break partial
        // selection. Taken at face value that made an empty selection report
        // formFactorFits = true, which contradicts the absent-category default
        // below and tells the customer their fit is fine before they have
        // chosen a case.
        //
        // So the vacuous pass is discarded here, where the whole selection is
        // visible, rather than inside the rule where it cannot be. Unknown
        // reports as false and the panel shows a dash.
        $hasBoth = ($selection['motherboard'] ?? null) !== null && ($selection['case'] ?? null) !== null;

        return [
            'cpuMotherboard' => $results->get('cpu_motherboard', true),
            'ramSupported' => $results->get('memory', true),
            'powerEnough' => $results->get('power', true),
            'gpuClearance' => $results->get('clearance', true),
            // Defaults FALSE on purpose - see the docblock. A case fit the
            // customer cannot see is worse than a warning they can act on.
            'formFactorFits' => $hasBoth && (bool) $results->get('form_factor', false),
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
