<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Component;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Price integrity gate (boss directive 2026-09-27: "ensure all prices are for
 * live available stock due to price fluctuations and current market
 * conditions").
 *
 * The catalogue is a scraped snapshot, so two failure modes exist:
 *
 * 1. **Corrupted rows.** Scraper runs occasionally capture a bundle price, a
 * multi-module price, or a pack-of-N price instead of the unit price. Real
 * examples found 2026-09-27: "G.Skill Ripjaws V 128 GB" at GBP 3,617.99,
 * "PNY Performance 16 GB" at GBP 874.96, "Samsung 870 Evo" at GBP 2,302.00, and
 * an "Asus ROG Astral OC HATSUNE MIKU EDITION" RTX 5080 at GBP 7,000. A build
 * that picks one of those quotes an absurd number to a customer and burns
 * the 100% 5-star reputation - so these rows must never reach a build pool
 * or a marketing surface.
 * 2. **Stale prices.** Even a correct price decays as the market moves. A
 * price is only safe to publish while it is still inside its freshness
 * window (see `freshness()`), and that is enforced on the quoting side.
 *
 * Detection is deliberately conservative and *relative* rather than an
 * absolute price list, so it stays correct as the market moves:
 *
 * - **Family pass** - parts are grouped by chipset (CPUs/GPUs, which carry a
 * real chipset) or by name-with-capacity-stripped (everything else). In any
 * family with at least 3 rows, a price more than 40% away from the family
 * median is treated as corrupt rather than as a genuine market spread.
 * - **Capacity pass** - for RAM and storage, where families are internally
 * consistent but collectively wrong (17 "Corsair Vengeance RGB 32 GB" rows
 * all between GBP 370 and GBP 601 for a kit that really costs ~GBP 120), parts are
 * re-grouped by the capacity in the name and compared against the
 * cross-family median for that capacity. A 32GB kit priced at more than 60%
 * away from the 32GB median is corrupt.
 */
class PriceIntegrityService
{
    /** Deviation from the family median (percentage, as a fraction). */
    public const FAMILY_TOLERANCE = 0.40;

    /** Deviation from the cross-family capacity median (RAM/storage). */
    public const CAPACITY_TOLERANCE = 0.60;

    /** Minimum rows before a family/median is trusted. */
    private const MIN_FAMILY_ROWS = 3;

    private const MIN_CAPACITY_ROWS = 5;

    /** A price is publishable for 7 days; after that it must be re-verified. */
    public const FRESH_DAYS = 7;

    /**
     * Should unverified prices be withheld from customer-facing build pools?
     *
     * OFF by default, and that default is load-bearing rather than cautious.
     *
     * Measured against live Neon on 2026-09-28, immediately after the
     * price_checked_at migration ran for the first time: all 2,708 production
     * components have price_checked_at = NULL, because the merchant-evidence
     * refresh has never been run against production. With the freshness
     * rejection active, clean() returns 0 of 2,708 publishable, the measured
     * band floors collapse to GBP 0.00 for every resolution, and the
     * configurator would present the customer with an empty parts list. The
     * gate would not be a quality improvement, it would be a total outage -
     * and it would fire on the very deploy meant to improve the site.
     *
     * The honest position is that the whole live catalogue is currently
     * unevidenced, which is a sourcing problem, not something a code flag can
     * fix. So the gate runs and *reports* by default, and only withholds from
     * the quoting surface once a real feed (Awin/retailer stock, not PCPP) is
     * feeding evidence at sufficient coverage. Turn it on deliberately, with
     * the coverage numbers to hand, never as a side effect of a deploy.
     */
    public static function enforcesFreshness(): bool
    {
        return (bool) config('price_integrity.enforce_freshness', false);
    }

    /**
     * Component ids whose price cannot be trusted (corrupt/outlier).
     *
     * @return array<int, true>
     */
    public function quarantinedIds(): array
    {
        $cached = Cache::get('price_integrity_quarantine');

        if (is_array($cached)) {
            return $cached;
        }

        // Every active row, verified or not. This is the REFERENCE population:
        // a row that has been explicitly checked against a live source is
        // human/agent-verified ground truth, and ground truth has to be part of
        // the population a median is computed from.
        //
        // The earlier version threw these rows away before building the
        // references, on the reasonable-sounding grounds that a verified price
        // "outranks any heuristic". The effect was exactly backwards. A refresh
        // verified ~2,468 rows, the remaining unverified population dropped
        // below MIN_FAMILY_ROWS/MIN_CAPACITY_ROWS for every cohort, every
        // median loop skipped itself, and the guard went blind precisely when
        // the catalogue became trustworthy. A phantom "64GB DDR5 6000" row at
        // GBP 219 (against a live 64GB median of GBP 1,075) rode straight
        // through and became the cheapest RAM the recommender could pick.
        //
        // Verified rows are exempt from being JUDGED by the reference. They are
        // not exempt from POPULATING it.
        $all = Component::query()
            ->select(['id', 'category_id', 'name', 'chipset', 'specs', 'price', 'price_checked_at'])
            ->where('active', true)
            ->get();

        // The rows still under suspicion: everything the reference cannot vouch
        // for. This is what gets judged, and it is the only thing that can be
        // flagged.
        $rows = $all->filter(fn (Component $row) => $row->price_checked_at === null);

        $flagged = [];

        // --- Pass 1: family median ---------------------------------------
        // The family reference is built from every row in the family (so it
        // stays meaningful once the catalogue is verified) while only the
        // unverified rows in it are judged.
        $families = [];
        foreach ($all as $row) {
            $families[$this->familyKey($row)][] = $row;
        }

        foreach ($families as $items) {
            if (count($items) < self::MIN_FAMILY_ROWS) {
                continue;
            }

            $byProduct = [];
            foreach ($items as $row) {
                $byProduct[(string) $row->name][] = (float) $row->price;
            }

            $references = [];
            foreach ($byProduct as $prices) {
                $references[] = $this->median($prices);
            }

            $median = $this->median($references);
            if ($median <= 0) {
                continue;
            }

            foreach ($items as $row) {
                if ($row->price_checked_at !== null) {
                    continue;
                }

                if ($this->deviates((float) $row->price, $median, self::FAMILY_TOLERANCE)) {
                    $flagged[(int) $row->id] = true;
                }
            }
        }

        // --- Pass 2: capacity median for RAM + storage --------------------
        //
        // Two corrections to how this reference is built, both forced by a
        // phantom row that survived the earlier version.
        //
        // (a) The reference is computed from EVERY active row, verified or not.
        //     This used to run on unverified rows only, on the reasonable
        //     grounds that a verified price outranks any heuristic. The effect
        //     was the opposite of safe: once a refresh verified ~2,468 rows the
        //     remaining unverified population fell below the 5-row minimum for
        //     every cohort, the loop skipped them all, and the guard went blind
        //     precisely when the catalogue became trustworthy. Verified rows
        //     are ground truth and belong in the reference population; they are
        //     only exempt from being *judged* by it.
        //
        // (b) RAM is keyed by capacity AND memory type, with a fallback to the
        //     pooled capacity when the typed cohort is too small to be a
        //     reference. A 64GB DDR4 kit (~GBP 250) and a 64GB DDR5-6000 kit
        //     (~GBP 1,075) share a capacity but not a price class, so pooling
        //     them is meaningless. Most legacy rows do not declare their type,
        //     so the typed cohorts are sparse - which is why the fallback
        //     exists rather than a hard split that would starve them.
        $verified = [];
        foreach ($all as $row) {
            if ($row->price_checked_at !== null) {
                $verified[(int) $row->id] = true;
            }
        }

        $typed = [];
        $pooled = [];
        foreach ($all as $row) {
            if (! in_array((int) $row->category_id, [5, 6], true)) {
                continue;
            }
            $capacity = $this->capacityKey((string) $row->name);
            if ($capacity === null) {
                continue;
            }
            $pooled[$capacity][] = $row;

            if ((int) $row->category_id === 5) {
                $type = $this->memoryTypeKey($row);
                if ($type !== null) {
                    $typed[$capacity.' '.$type][] = $row;
                }
            }
        }

        // Prefer the like-for-like cohort; fall back to the pooled one.
        $capacities = [];
        foreach ($pooled as $capacity => $items) {
            $typedItems = [];
            foreach (array_keys($typed) as $key) {
                if (str_starts_with($key, $capacity.' ')) {
                    $typedItems = $typed[$key];
                }
            }

            $capacities[$capacity] = count($typedItems) >= self::MIN_CAPACITY_ROWS
                ? $typedItems
                : $items;
        }

        foreach ($capacities as $items) {
            if (count($items) < self::MIN_CAPACITY_ROWS) {
                continue;
            }

            // One vote per distinct product, not per row. A capacity reference
            // is a statement about the capacity ("what does 32GB cost?"), so a
            // product that happens to have been scraped 17 times must not get
            // 17 votes in it. Measured on the live catalogue the 32GB reference
            // moved only GBP 399.95 -> GBP 399.71 under this change, which is
            // the useful part: the reference was already sound rather than
            // skewed, so nothing legitimate was re-admitted or newly rejected.
            // Cross-checked against live UK prices 2026-09-27: a 2x16GB
            // DDR5-6000 CL30 kit averages ~GBP 398 (whereismyram UK chart) and
            // Scan lists the Corsair Vengeance Black 32GB DDR5-6000 at
            // GBP 553.99, so ~GBP 400 is the correct reference level and the
            // generic GBP 119.00 "32GB DDR5 6000" row is a true outlier.
            $byProduct = [];
            foreach ($items as $row) {
                $byProduct[(string) $row->name][] = (float) $row->price;
            }

            $references = [];
            foreach ($byProduct as $prices) {
                $references[] = $this->median($prices);
            }

            $median = $this->median($references);
            if ($median <= 0) {
                continue;
            }

            foreach ($items as $row) {
                // Ground-truth rows are never judged by the heuristic; only the
                // rows still lacking a live check are candidates for a flag.
                if (isset($verified[(int) $row->id])) {
                    continue;
                }

                if ($this->deviates((float) $row->price, $median, self::CAPACITY_TOLERANCE)) {
                    $flagged[(int) $row->id] = true;
                }
            }
        }

        Cache::put('price_integrity_quarantine', $flagged, now()->addHours(12));

        return $flagged;
    }

    /**
     * Remove unsellable rows from a candidate pool.
     *
     * Two classes of row are removed here, and the distinction matters:
     *
     * 1. Quarantined rows - a price that actively contradicts its own cohort.
     * 2. Unverifiable rows - a component we have never been able to check
     *    against a live source, because it carries no source_url at all.
     *
     * The second class used to survive pool cleaning and only get caught at
     * publish time by the freshness gate, which is safe but wasteful: the
     * recommender scored those rows, picked one (a "64GB DDR5 6000" kit at
     * GBP 219, against a live 64GB DDR5 median of GBP 1,075) and then the gate
     * refused the build it had just designed. A row we cannot verify is a row
     * we cannot sell, so it must not be offered in the first place.
     *
     * Pools are only ever *narrowed* here - a healthy pool is untouched, and
     * an emptied pool simply means the relax-to-cheapest fallback kicks in,
     * which is the correct outcome (better to build something honest than to
     * quote a corrupt price).
     *
     * @param  Collection<int, Component>  $pool
     * @return Collection<int, Component>
     */
    public function clean(Collection $pool): Collection
    {
        $quarantined = $this->quarantinedIds();
        $enforce = self::enforcesFreshness();

        return $pool->reject(function (Component $component) use ($quarantined, $enforce) {
            if (isset($quarantined[(int) $component->id])) {
                return true;
            }

            $source = trim((string) $component->source_url);

            if ($source === '') {
                return true;
            }

            // Unverified prices are excluded too, not just unsourced ones.
            //
            // Measured 2026-09-28: a GBP 1,000 ask picked the cheapest 32GB kit
            // in the pool, a G.Skill Ripjaws V 32 GB at GBP 230.74, and because
            // that row was one of the 204 parts the refresh could find no offers
            // for, it was 24 days stale - which made attachPublishGate() reject
            // the WHOLE build as unquotable. One unverified part was poisoning a
            // perfectly sellable GBP 1,020 machine, and the fresher kit sitting
            // GBP 20 higher would have published immediately.
            //
            // The general rule: a part we cannot evidence costs us the entire
            // order, so it is never worth saving a few pounds on. The picker
            // must choose from parts we can actually stand behind.
            //
            // Gated by enforcesFreshness() - see that method for why this is off
            // until real evidence coverage exists. When it is off, corrupt/quarantined
            // rows are still rejected, only the freshness rejection is withheld,
            // so the gate still removes known-bad prices without emptying the shop.
            return $enforce && ! $this->freshness($component)['fresh'];
        });
    }

    /**
     * Capacity cohorts whose own prices disagree wildly.
     *
     * A median is only as trustworthy as the population behind it. When a
     * cohort's interquartile spread is wider than the tolerance used to police
     * it, no single reference price can be called "the" price for that
     * capacity - which means the cohort must be live-verified before it is
     * quoted, rather than quietly policed by a poisoned median.
     *
     * @return array<string, array{rows: int, products: int, p25: float, median: float, p75: float, trusted: bool}>
     */
    public function untrustedCohorts(): array
    {
        $report = [];

        $categories = Category::query()
            ->whereIn('id', [5, 6])
            ->pluck('id', 'slug');

        foreach ($categories as $slug => $categoryId) {
            $rows = Component::query()
                ->select(['id', 'category_id', 'name', 'price'])
                ->where('active', true)
                ->where('stock', '>', 0)
                ->where('category_id', $categoryId)
                ->get();

            $grouped = [];
            foreach ($rows as $row) {
                $capacity = $this->capacityKey((string) $row->name);
                if ($capacity !== null) {
                    $grouped[$capacity][] = (float) $row->price;
                }
            }

            foreach ($grouped as $capacity => $prices) {
                if (count($prices) < self::MIN_CAPACITY_ROWS) {
                    continue;
                }

                sort($prices);
                $p25 = $this->percentile($prices, 0.25);
                $median = $this->median($prices);
                $p75 = $this->percentile($prices, 0.75);

                $spread = $median > 0 ? ($p75 - $p25) / $median : 0.0;

                $report[$slug.' '.$capacity] = [
                    'rows' => count($prices),
                    'products' => count(array_unique(array_map(
                        fn ($r) => (string) $r->name,
                        $rows->filter(fn ($x) => $this->capacityKey((string) $x->name) === $capacity)->all()
                    ))),
                    'p25' => $p25,
                    'median' => $median,
                    'p75' => $p75,
                    'trusted' => $spread <= self::CAPACITY_TOLERANCE,
                ];
            }
        }

        return $report;
    }

    /**
     * @param  array<int, float>  $sorted
     */
    private function percentile(array $sorted, float $percentile): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) floor($percentile * (count($sorted) - 1));

        return (float) $sorted[$index];
    }

    /**
     * Is this component's price still inside the publish window?
     *
     * @return array{fresh: bool, days: int, checked_at: ?string, reason: string}
     */
    public function freshness(Component $component): array
    {
        // Deliberately NO fallback to updated_at. An earlier version used
        // `$component->price_checked_at ?? $component->updated_at`, which is
        // wrong in a way that only shows up on newly created rows: a component
        // inserted or edited today with a scraped price that was NEVER checked
        // against a live source read as "fresh" for a full 7 days, because
        // updated_at was recent. The record of when a row was written is not
        // evidence of when its price was verified. Verified 2026-09-28 by
        // test a stamped row is fresh and survives pool cleaning.
        $checkedAt = $component->price_checked_at;

        if ($checkedAt === null || $checkedAt === '') {
            return [
                'fresh' => false,
                'days' => PHP_INT_MAX,
                'checked_at' => null,
                'reason' => 'Price has never been verified against a live source.',
            ];
        }

        $checked = Carbon::parse($checkedAt);
        $days = (int) $checked->diffInDays(now());

        if ($days > self::FRESH_DAYS) {
            return [
                'fresh' => false,
                'days' => $days,
                'checked_at' => $checkedAt,
                'reason' => 'Price last checked '.$days.' days ago - outside the '
                .self::FRESH_DAYS.'-day publish window.',
            ];
        }

        return [
            'fresh' => true,
            'days' => $days,
            'checked_at' => (string) $checkedAt,
            'reason' => 'Price verified '.($days === 0 ? 'today' : $days.' day(s) ago').'.',
        ];
    }

    /**
     * Staleness summary for a whole build. `publishable` is false when any
     * part has aged out, which is what the quoting/marketing surfaces check.
     *
     * The pickers return plain selection arrays (id/name/price/score), not
     * models, so ids are resolved in one query. Anything that cannot be
     * resolved is treated as UNVERIFIED rather than skipped: a part we cannot
     * look up is exactly the part we must not quote.
     *
     * @param  array<string, Component|array|int|null>  $components
     * @return array{publishable: bool, oldest_days: int, stale: array<int, int>, unverified: array<int, string>, rows: array<string, array>}
     */
    public function buildFreshness(array $components): array
    {
        // Resolve selection arrays to models in a single query.
        $ids = [];
        foreach ($components as $component) {
            if ($component instanceof Component) {
                continue;
            }
            $id = is_array($component) ? ($component['id'] ?? null) : (is_scalar($component) ? (int) $component : null);
            if ($id) {
                $ids[(int) $id] = true;
            }
        }

        $fetched = $ids === [] ? collect() : Component::whereIn('id', array_keys($ids))->get()->keyBy('id');

        $rows = [];
        $stale = [];
        $unverified = [];
        $oldest = 0;

        foreach ($components as $slug => $component) {
            if ($component === null) {
                continue;
            }

            $model = $component instanceof Component
            ? $component
            : $fetched->get(is_array($component) ? ($component['id'] ?? null) : (int) $component);

            if (! $model instanceof Component) {
                // Cannot verify what we cannot look up - never a pass.
                $unverified[] = (string) $slug;

                continue;
            }

            $state = $this->freshness($model);
            $rows[$slug] = $state;

            if (! $state['fresh']) {
                $stale[] = (int) $model->id;
                $oldest = max($oldest, $state['days'] === PHP_INT_MAX ? 9999 : $state['days']);
            }
        }

        return [
            'publishable' => $stale === [] && $unverified === [],
            'oldest_days' => $oldest,
            'stale' => $stale,
            'unverified' => $unverified,
            'rows' => $rows,
        ];
    }

    /**
     * Record that a price was just checked against a live source.
     *
     * Stamping also re-opens the row to the build pools: `quarantinedIds()`
     * skips verified rows, so confirming a price by hand is the way to bring a
     * legitimate outlier (a Founder's Edition priced below the AIB median, a
     * genuinely rare limited edition) back into circulation.
     */
    public function markChecked(
        Component|int $component,
        float $price,
        ?int $stock = null,
        ?string $sourceUrl = null,
    ): void {
        $model = $component instanceof Component ? $component : Component::find($component);

        if ($model === null) {
            return;
        }

        $model->price = round($price, 2);
        if ($stock !== null) {
            $model->stock = $stock;
        }

        // Where the price was actually EVIDENCED - the retailer's own page, which
        // is the only thing that proves the part is buyable today.
        //
        // This deliberately does NOT overwrite `source_url`. That column is the
        // component's PCPartPicker product page and is what the refresh command
        // follows to re-check the row, so overwriting it with a retailer URL made
        // the row permanently unrefreshable: the next run saw a non-PCPP
        // source_url, reported "no source url" and skipped it forever. The
        // merchant URL belongs in the specs JSON, which is where audit metadata
        // already lives, and must never replace the refresh handle.
        if (is_string($sourceUrl) && trim($sourceUrl) !== '') {
            $specs = $model->specs;
            if (is_string($specs)) {
                $specs = json_decode($specs, true);
            }
            if (! is_array($specs)) {
                $specs = [];
            }
            $specs['price_evidence_url'] = trim($sourceUrl);
            $model->specs = $specs;
        }

        $model->price_checked_at = now();
        $model->save();

        // The stamp changes the verdict, so the cached quarantine is stale.
        $this->forget();
    }

    /** Drop the cached quarantine so the next pool rebuild recomputes it. */
    public function forget(): void
    {
        Cache::forget('price_integrity_quarantine');
    }

    /**
     * Family key: chipset for CPU/GPU (real comparable part), otherwise the
     * name with any capacity token stripped so "X 32 GB" and "X 64 GB" group
     * as one family.
     */
    private function familyKey(Component $component): string
    {
        $category = (int) $component->category_id;

        if (in_array($category, [1, 4], true) && $component->chipset) {
            return $category.'|'.strtoupper((string) $component->chipset);
        }

        $name = (string) $component->name;
        $name = (string) preg_replace('/\b\d+\s?(?:GB|TB)\b/i', '', $name);
        $name = (string) preg_replace('/\s+/', ' ', trim($name));

        return $category.'|~'.strtoupper($name);
    }

    /** Capacity bucket for RAM/storage, e.g. "32GB", "1TB", or null. */
    private function capacityKey(string $name): ?string
    {
        if (preg_match('/(\d+)\s?(GB|TB)\b/i', $name, $m) === 1) {
            return $m[1].strtoupper($m[2]);
        }

        return null;
    }

    /**
     * Which memory generation a RAM row belongs to.
     *
     * Prefers the structured spec, then falls back to the product name, because
     * the two disagree often enough to matter: the catalogue mixes rows that
     * declare their type ("64GB DDR5 6000") with rows that only hint at it
     * ("Crucial Pro 32 GB"), and a cohort reference is only as good as its
     * keying.
     */
    private function memoryTypeKey(Component $row): ?string
    {
        $specs = $row->specs;
        if (is_string($specs)) {
            $specs = json_decode($specs, true);
        }

        if (is_array($specs) && ! empty($specs['type'])) {
            $t = strtoupper((string) $specs['type']);
            if (str_contains($t, 'DDR5')) {
                return 'ddr5';
            }
            if (str_contains($t, 'DDR4') || str_contains($t, 'DDR3')) {
                return 'ddr4';
            }
        }

        if (preg_match('/\bDDR\s?-?([345])\b/i', (string) $row->name, $m) === 1) {
            return 'ddr'.strtolower($m[1]);
        }

        return null;
    }

    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function deviates(float $price, float $median, float $tolerance): bool
    {
        if ($median <= 0 || $price <= 0) {
            return false;
        }

        return abs(($price - $median) / $median) > $tolerance;
    }
}
