<?php

namespace App\Services;

/**
 * Match normalised Awin feed rows to catalogue components by identity.
 *
 * Identity is decided in a strict confidence order, and only an exact machine
 * identifier is treated as trustworthy:
 *
 *   high   - GTIN/EAN/UPC (any of the component's gtin/ean/upc/isbn keys, or a
 *            digit-run SKU) equals the feed's GTIN. A GTIN is a global trade
 *            item number: it identifies exactly one product.
 *   medium - MPN (manufacturer part number) equals the feed's MPN. Good, but
 *            not global - a reseller can reuse/garble them.
 *   low    - normalised product NAME equals the feed's name, AND is unique on
 *            both sides. Only "low" because names are not identity: "Corsair
 *            Vengeance RGB 32GB" is not one product but several SKUs, and two
 *            different capacities can share a name.
 *
 * Ambiguity fails CLOSED: if two feed rows share a name, that name is dropped
 * entirely (counted as ambiguous) rather than picking the first - picking one
 * would evidence a random component with a random price (Rule 6).
 *
 * The matcher only decides IDENTITY. Deciding that a feed price should become
 * a catalogue price is a separate, deliberate step (prices:ingest-feed), so a
 * mis-match can never silently reprice a product on its own.
 */
class MerchantFeedMatcher
{
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    /** @var list<string> */
    public const CONFIDENCE_ORDER = [self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW];

    /**
     * @param  iterable<array<string, mixed>>  $feedRows  rows from AwinFeedService::parseFeed()
     * @param  iterable<int, \App\Models\Component|array<string, mixed>>  $components
     * @return array{
     *   matches: array<int, array{index: int, row: array<string, mixed>, key: string, confidence: string}>,
     *   counts: array<string, int>,
     *   total_components: int,
     *   unmatched_components: int,
     *   matched_rows: int,
     *   unmatched_rows: int,
     *   ambiguous_names: int
     * }
     */
    public function match(iterable $feedRows, iterable $components): array
    {
        // Accept an array or a Collection: the command hands over a Collection,
        // and indexing by position is what the match records refer to, so
        // re-key a Collection to a plain list first (Rule 3: a type error from
        // a mis-shaped call is a bug, not something to paper over at the call
        // site).
        $feedRows = is_array($feedRows) ? array_values($feedRows) : array_values(iterator_to_array($feedRows, false));

        // Index the feed. A key pointing at more than one row is ambiguous and
        // is never used (except to count), which is what stops a duplicate name
        // or a duplicated MPN from picking the wrong price.
        $byGtin = [];
        $byMpn = [];
        $byName = [];

        foreach ($feedRows as $index => $row) {
            $gtin = $this->digits($row['gtin'] ?? null);
            if ($gtin !== null) {
                $byGtin[$gtin][] = $index;
            }

            $mpn = $this->normKey($row['mpn'] ?? null);
            if ($mpn !== null) {
                $byMpn[$mpn][] = $index;
            }

            $name = $this->normName($row['name'] ?? null);
            if ($name !== null) {
                $byName[$name][] = $index;
            }
        }

        $matches = [];
        $counts = ['gtin' => 0, 'mpn' => 0, 'name' => 0];
        $matchedRows = [];
        $ambiguousNames = 0;
        $unmatched = 0;
        $total = 0;

        // A feed row may be claimed by AT MOST ONE component, and the index
        // (not just the key) is what is claimed.
        //
        // This was a real defect caught by its own test: three catalogue rows
        // that shared a GTIN all matched the same feed row, so a single
        // merchant price was going to be stamped onto three products. GTINs are
        // supposed to be unique, but duplicate GTINs in a real scraped
        // catalogue are exactly the case that cannot be assumed away, and the
        // failure mode is quietly repricing a sellable part.
        //
        // The first component in iteration order wins; later ones fall through
        // to a weaker tier or to unmatched, which is the fail-closed outcome.
        $claimed = [];

        foreach ($components as $component) {
            $total++;
            $described = $this->describe($component);
            $id = $described['id'];

            // 1) GTIN - the only strong identity signal.
            $gtinMatched = false;
            foreach ($this->gtinCandidates($described['specs'], $described['sku']) as $candidate) {
                $hits = $byGtin[$candidate] ?? null;
                if ($hits === null || count($hits) !== 1) {
                    continue;
                }
                $index = $hits[0];
                if (isset($claimed[$index])) {
                    continue; // already attached to another component
                }
                $matches[$id] = [
                    'index' => $index,
                    'row' => $feedRows[$index],
                    'key' => 'gtin',
                    'confidence' => self::CONFIDENCE_HIGH,
                ];
                $counts['gtin']++;
                $matchedRows[$index] = true;
                $claimed[$index] = true;
                $gtinMatched = true;
                break;
            }
            if ($gtinMatched) {
                continue;
            }

            // 2) MPN - good, not global.
            $mpn = $this->normKey($described['specs']['mpn'] ?? null);
            $hits = $mpn !== null ? ($byMpn[$mpn] ?? null) : null;
            if ($hits !== null && count($hits) === 1) {
                $index = $hits[0];
                if (! isset($claimed[$index])) {
                    $matches[$id] = [
                        'index' => $index,
                        'row' => $feedRows[$index],
                        'key' => 'mpn',
                        'confidence' => self::CONFIDENCE_MEDIUM,
                    ];
                    $counts['mpn']++;
                    $matchedRows[$index] = true;
                    $claimed[$index] = true;
                    continue;
                }
            }

            // 3) Unique exact name - reportable, not automatically trusted.
            $name = $this->normName($described['name']);
            $hits = $name !== null ? ($byName[$name] ?? null) : null;
            if ($hits !== null) {
                $index = $hits[0];
                if (count($hits) === 1 && ! isset($claimed[$index])) {
                    $matches[$id] = [
                        'index' => $index,
                        'row' => $feedRows[$index],
                        'key' => 'name',
                        'confidence' => self::CONFIDENCE_LOW,
                    ];
                    $counts['name']++;
                    $matchedRows[$index] = true;
                    $claimed[$index] = true;
                    continue;
                }
                if (count($hits) === 1) {
                    // Unique name, but that row is already attached elsewhere.
                    // Counting it as ambiguous keeps the report honest.
                    $ambiguousNames++;
                    $unmatched++;
                    continue;
                }
                $ambiguousNames++;
            }

            $unmatched++;
        }

        return [
            'matches' => $matches,
            'counts' => $counts,
            'total_components' => $total,
            'unmatched_components' => $unmatched,
            'matched_rows' => count($matchedRows),
            'unmatched_rows' => count($feedRows) - count($matchedRows),
            'ambiguous_names' => $ambiguousNames,
        ];
    }

    /**
     * Normalise one component (model or array) to the fields used for matching.
     *
     * @return array{id: int, name: ?string, sku: ?string, specs: array<string, mixed>}
     */
    private function describe(\App\Models\Component|array $component): array
    {
        if (is_array($component)) {
            $id = (int) ($component['id'] ?? 0);
            $name = isset($component['name']) ? (string) $component['name'] : null;
            $sku = isset($component['sku']) ? (string) $component['sku'] : null;
            $specs = $component['specs'] ?? [];
        } else {
            $id = (int) $component->id;
            $name = (string) $component->name;
            $sku = $component->sku !== null ? (string) $component->sku : null;
            $specs = $component->specs ?? [];
        }

        if (is_string($specs)) {
            $specs = json_decode($specs, true);
        }
        if (! is_array($specs)) {
            $specs = [];
        }

        return ['id' => $id, 'name' => $name, 'sku' => $sku, 'specs' => $specs];
    }

    /**
     * Every GTIN-ish token this component exposes, normalised to digits.
     *
     * GTIN lengths are 8 (EAN-8), 12 (UPC-A), 13 (EAN-13) and 14 (ITF-14);
     * anything else is treated as an MPN-ish string, not a GTIN, so we never
     * "match" a part number as if it were a trade item number.
     *
     * @param  array<string, mixed>  $specs
     * @return list<string>
     */
    private function gtinCandidates(array $specs, ?string $sku): array
    {
        $out = [];
        $keys = ['gtin', 'product_gtin', 'ean', 'upc', 'isbn'];

        foreach ($keys as $key) {
            if (! isset($specs[$key])) {
                continue;
            }
            $d = $this->digits(is_scalar($specs[$key]) ? (string) $specs[$key] : null);
            if ($d !== null && $this->isGtinLength($d) && ! in_array($d, $out, true)) {
                $out[] = $d;
            }
        }

        // Some SKUs are a bare EAN/GTIN; only trust it at GTIN length.
        $d = $this->digits($sku);
        if ($d !== null && $this->isGtinLength($d) && ! in_array($d, $out, true)) {
            $out[] = $d;
        }

        return $out;
    }

    private function isGtinLength(string $digits): bool
    {
        return in_array(strlen($digits), [8, 12, 13, 14], true);
    }

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $value);

        return $digits === '' ? null : $digits;
    }

    /** Uppercase alphanumeric key (MPN-ish). */
    private function normKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = (string) preg_replace('/[^A-Za-z0-9]+/', '', $value);

        return $key === '' ? null : strtoupper($key);
    }

    /** Lowercase, punctuation-to-space, collapsed name key. */
    private function normName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $name = strtolower(trim($value));
        $name = (string) preg_replace('/[^a-z0-9]+/', ' ', $name);
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        return $name === '' ? null : $name;
    }
}
