<?php

namespace App\Services;

/**
 * Resolve a PCTG Business system type into something actionable.
 *
 * A system type ("CAD Workstation") is a sales concept. What the builder needs
 * is a workload, a verdict on whether we can build it, and an honest note when
 * the thing the job type needs is above what our component builder produces.
 *
 * THE TWO WAYS A SYSTEM TYPE CAN BE UNSERVED, and they need different words
 *
 *   1. WE DO NOT STOCK THE PARTS. The capability gate says so - no ECC memory,
 *      no enterprise drives, no professional GPUs. The workload is real and we
 *      want the sale; it is a spec conversation until we can assemble one.
 *
 *   2. THE SPEC IS BELOW OUR BUILDING FLOOR. A home office machine wants 16GB.
 *      AIRecommendationService::MIN_RAM_GB is 32, and the configurator will
 *      never quote below it. That is not a missing part - it is a floor we
 *      chose, and the honest response is to say the machine exists but the
 *      online configurator cannot quote one, rather than quietly quoting 32GB
 *      and hoping nobody notices it is twice what they asked for.
 *
 * Both are reported separately. Collapsing them into one "unavailable" state
 * would hide the second from whoever fixes it, and the second is the easier one
 * to fix.
 */
class BusinessSystemType
{
    /**
     * @return array<int, array<string, mixed>> every system type, for a menu
     */
    public function all(): array
    {
        $out = [];

        foreach ((array) config('business.systems', []) as $slug => $system) {
            $out[] = ['slug' => $slug] + $system;
        }

        return $out;
    }

    /**
     * System types grouped by business size.
     *
     * @return array<string, array{label: string, blurb: string, headcount: string, systems: array<int, array>}>
     */
    public function bySize(): array
    {
        $grouped = [];

        foreach ((array) config('business.sizes', []) as $size => $meta) {
            $grouped[$size] = $meta + ['systems' => []];
        }

        foreach ($this->all() as $system) {
            $size = $system['size'] ?? null;

            if ($size === null || ! isset($grouped[$size])) {
                // A system type pointing at a size that does not exist is a
                // config error. Surfaced rather than dropped, because a silently
                // invisible system type is a product that cannot be sold and
                // nobody will notice until a customer asks for it.
                \Log::warning('business system type points at an unknown size', [
                    'system' => $system['slug'] ?? null,
                    'size' => $size,
                ]);

                continue;
            }

            $grouped[$size]['systems'][] = $system;
        }

        return $grouped;
    }

    /**
     * One system type, resolved.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $system = config('business.systems.'.$slug);

        if (! is_array($system)) {
            return null;
        }

        return ['slug' => $slug] + $system + $this->resolve($slug, $system);
    }

    /**
     * @param  array<string, mixed>  $system
     * @return array<string, mixed>
     */
    private function resolve(string $slug, array $system): array
    {
        $gate = app(BusinessCapabilityGate::class);

        // Capabilities and ADVISORY requirements are kept apart on purpose.
        // `business_manageable` and `compact_chassis` cannot be answered from
        // component specs, and pretending otherwise would be inventing a claim
        // about a board's firmware.
        $required = (array) ($system['requires'] ?? []);
        $capabilityKeys = array_values(array_filter(
            $required,
            fn (string $k) => array_key_exists($k, (array) config('workloads.capabilities', []))
        ));
        $advisoryKeys = array_values(array_filter(
            $required,
            fn (string $k) => array_key_exists($k, (array) config('business.advisory', []))
        ));

        // The gate is asked about a synthetic workload carrying just this
        // system's capabilities, so a system type cannot smuggle in a requirement
        // the workload itself does not have.
        $verdict = $gate->verdictForCapabilities($capabilityKeys);

        $minRam = (int) ($system['minRamGb'] ?? 0);
        $band = (array) ($system['band'] ?? []);
        $bandFloor = (float) ($band[0] ?? 0);

        /*
         * THE BAND CHECK, and why the obvious version of it was wrong.
         *
         * The first version asked whether the job's memory floor sat BELOW
         * AIRecommendationService::MIN_RAM_GB, and reported every 16GB system
         * type as unservable. That is backwards. 32GB is a floor, not a
         * ceiling: a job that needs 16GB is perfectly served by a 32GB machine,
         * and one that needs 64GB is served too, because the RAM pool holds
         * 48GB and 64GB kits. Nothing here is blocked by the memory floor.
         *
         * The real problem is the PRICE. A GBP 700 home-office band sits below
         * the cheapest machine the configurator can actually build - GBP 1,060,
         * measured from the live catalogue. Offering a GBP 700 starting point
         * we cannot honour is the same class of mistake as quoting a NAS build
         * we cannot assemble, just cheaper.
         */
        $builderFloor = $this->builderEntryPrice();
        $bandUnreachable = $bandFloor > 0 && $builderFloor > 0 && $bandFloor < $builderFloor;

        $unbuildable = $verdict['missing'] !== [];

        return [
            'verdict' => $verdict,
            'advisory' => array_map(
                fn (string $k) => (string) (config('business.advisory.'.$k) ?? $k),
                $advisoryKeys
            ),
            'missing_labels' => $verdict['missing_labels'],
            // Two distinct reasons, never collapsed.
            'buildable' => ! $unbuildable,
            'part_blocked' => $unbuildable,
            'minRamGb' => $minRam,
            'band' => $band,
            'band_floor' => $bandFloor,
            'builder_entry_price' => $builderFloor,
            'band_unreachable' => $bandUnreachable,
            // What to tell the customer when we cannot serve it.
            'unavailable_reason' => $this->reason($unbuildable, $bandUnreachable, $verdict, $bandFloor, $builderFloor),
        ];
    }

    /**
     * The cheapest complete machine the configurator can actually build.
     *
     * From workableBands(), which measures it against the live catalogue, so
     * this is never a hand-typed constant. Null when nothing can be measured,
     * in which case the band check is skipped rather than guessed at.
     */
    private function builderEntryPrice(): ?float
    {
        static $resolved = false;
        static $price = null;

        if ($resolved) {
            return $price;
        }

        try {
            $bands = app(AIRecommendationService::class)->workableBands();

            $floors = [];
            foreach ((array) $bands as $band) {
                if (isset($band['min'])) {
                    $floors[] = (float) $band['min'];
                }
            }

            $price = $floors === [] ? null : min($floors);
        } catch (\Throwable $e) {
            // Fail soft: no measurement means no band claim, which is the safe
            // direction. Guessing a floor here would be inventing a price.
            //
            // $price stays null and $resolved is set BELOW either way, so a
            // transient failure does not re-attempt a full catalogue measurement
            // for every system type in the loop.
            \Log::warning('could not measure the builder entry price for a business band check', [
                'reason' => $e->getMessage(),
            ]);

            $price = null;
        }

        $resolved = true;

        return $price;
    }

    /**
     * @param  array<string, mixed>  $verdict
     */
    private function reason(bool $partBlocked, bool $bandUnreachable, array $verdict, float $bandFloor, ?float $builderFloor): string
    {
        if ($partBlocked && $bandUnreachable) {
            return 'We do not yet stock the parts this needs ('
                .implode(', ', $verdict['missing_labels']).'), and it starts below the '
                .self::money($builderFloor).' we can actually build.';
        }

        if ($partBlocked) {
            return 'We do not yet stock the parts this needs: '
                .implode(', ', $verdict['missing_labels']).'.';
        }

        if ($bandUnreachable) {
            return 'Starts from about '.self::money($builderFloor).' - that is the least we can '
                .'fully build with the warranty on it.';
        }

        return '';
    }

    private static function money(?float $value): string
    {
        return $value === null ? 'our minimum build' : 'GBP '.number_format($value, 0);
    }
}
