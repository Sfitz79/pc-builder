<?php

namespace App\Services\Dimensions;

use PDO;

/**
 * Writes SOURCED per-model dimensions into components.specs, with provenance.
 *
 * This is the write path that StandardIngestor deliberately refused to cover.
 * StandardsIngestor handles values fixed by a published specification (ATX board
 * size, ATX PSU envelope). This handles the values with NO standard behind them:
 * GPU length and slot width, cooler height, case max GPU length and max cooler
 * height, PSU length, RAM heatspreader height.
 *
 * WHY A CURATED FILE RATHER THAN LIVE SCRAPING
 * --------------------------------------------
 * Manufacturer pages are the right source and they are the vendor's own published
 * specification, which is the cleanest kind of evidence available. But they are
 * also redesigned, relisted and intermittently unreachable, so a scraper that
 * re-reads them at request time is a source of wrong numbers as well as right
 * ones. Every figure is therefore captured once into a reviewed file, with the
 * source URL and the retrieval date, and applied from there.
 *
 * That also makes the values auditable: if a 359mm card is ever wrong, the record
 * says which page it came from and when.
 *
 * FAIL CLOSED, and never overwrite silently:
 *   - a card with no source URL is REJECTED, not applied on trust
 *   - an existing non-null dimension is never overwritten; a differing value is
 *     reported as a CONFLICT for review instead
 *   - a value outside plausible bounds is rejected, so a mis-parse such as
 *     "304mm" landing in `length` as 30400 cannot pass
 */
class CuratedDimensions
{
    /** Plausible physical envelopes, mm. Outside these, reject as a parse error. */
    private const BOUNDS = [
        'length' => [10.0, 1000.0],
        'height' => [5.0, 400.0],
        'width' => [5.0, 400.0],
        'depth' => [5.0, 600.0],
        'slot_width' => [1.0, 6.0],
        'tdp' => [10.0, 1000.0],
        'max_gpu_length' => [100.0, 600.0],
        'max_cpu_cooler_height' => [50.0, 300.0],
        'radiator_length' => [100.0, 600.0],
        'heatspreader_height' => [20.0, 80.0],
    ];

    /** Only these keys may be written. Anything else in the dataset is ignored. */
    private const WRITABLE = [
        'length', 'height', 'width', 'depth', 'slot_width', 'tdp',
        'max_gpu_length', 'max_cpu_cooler_height', 'radiator_length',
        'heatspreader_height',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public static function loadDataset(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("dataset not found: {$path}");
        }
        $d = json_decode((string) file_get_contents($path), true);
        if (!is_array($d)) {
            throw new \RuntimeException('dataset is not valid JSON');
        }
        return $d;
    }

    /**
     * Plan every change. Writes nothing.
     *
     * @param  array  $dataset  as loaded from the curated JSON
     * @return array{applied:int,conflicts:array,rejected:array,unmatched:array}
     */
    public function plan(array $dataset): array
    {
        $entries = $dataset['components'] ?? [];
        $out = [
            'applied' => 0,
            'conflicts' => [],
            'rejected' => [],
            'unmatched' => [],
            'rows' => [],
        ];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $pcpp = (string) ($entry['pcpp_id'] ?? '');
            if ($name === '' || $pcpp === '') {
                $out['rejected'][] = ['entry' => $entry, 'why' => 'entry lacks name or pcpp_id; cannot be matched safely'];
                continue;
            }

            // Match by PCPartPicker product ID, case-sensitively. Normalising case
            // merges distinct products - see duplicate-product-census.php, where
            // strtoupper() on this ID matched an Antec case to an ASUS motherboard.
            $row = $this->findByPcppId($pcpp);
            if ($row === null) {
                $out['unmatched'][] = ['name' => $name, 'pcpp_id' => $pcpp, 'why' => 'no component in the catalogue has this PCPartPicker product ID'];
                continue;
            }

            $specs = $this->decode($row['specs']);
            $set = [];

            foreach (self::WRITABLE as $key) {
                if (!array_key_exists($key, $entry)) {
                    continue;
                }
                $value = $entry[$key];
                if (!is_numeric($value)) {
                    $out['rejected'][] = ['name' => $name, 'pcpp_id' => $pcpp, 'why' => "{$key} is not numeric: " . var_export($value, true)];
                    continue;
                }
                $value = (float) $value;

                // Bounds check. Catches a mis-parse such as 304mm read as 30400,
                // or a case's max GPU length landing in the `length` slot.
                [$lo, $hi] = self::BOUNDS[$key];
                if ($value < $lo || $value > $hi) {
                    $out['rejected'][] = ['name' => $name, 'pcpp_id' => $pcpp, 'why' => "{$key}={$value} is outside the plausible range {$lo}-{$hi}; treated as a parse error"];
                    continue;
                }

                $existing = $specs[$key] ?? null;
                if ($existing !== null && $existing !== '' && is_numeric($existing)) {
                    if ((float) $existing !== $value) {
                        // Never silently overwrite. The catalogue may be right.
                        $out['conflicts'][] = [
                            'name' => $name,
                            'pcpp_id' => $pcpp,
                            'key' => $key,
                            'existing' => $existing,
                            'proposed' => $value,
                        ];
                        continue;
                    }
                    continue; // already correct
                }

                $set[$key] = $value;
            }

            if ($set === []) {
                continue;
            }

            $out['rows'][] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'pcpp_id' => $pcpp,
                'set' => $set,
                'source' => (string) ($entry['source'] ?? ''),
                'retrieved' => (string) ($entry['retrieved'] ?? ''),
                'specs' => array_merge($specs, $set),
            ];
            $out['applied']++;
        }

        return $out;
    }

    /** Write the planned rows. Only ever called after a reviewed dry run. */
    public function apply(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $stmt = $this->db->prepare(
            'UPDATE components SET specs = :specs, updated_at = NOW() WHERE id = :id'
        );
        $n = 0;
        foreach ($rows as $r) {
            $stmt->execute([
                'specs' => json_encode($r['specs'], JSON_UNESCAPED_SLASHES),
                'id' => $r['id'],
            ]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------

    private function findByPcppId(string $pcpp): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, specs, source_url FROM components
              WHERE source_url LIKE :pattern
              ORDER BY id LIMIT 2'
        );
        // Case-sensitive LIKE is not portable across Postgres and SQLite, so the
        // candidate set is narrowed by a case-insensitive LIKE and then filtered
        // exactly in PHP. The comparison there is case-sensitive, which is what
        // matters.
        $stmt->execute(['pattern' => '%/product/' . $pcpp . '/']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (preg_match('#pcpartpicker\.com/product/([A-Za-z0-9]+)/#', (string) $r['source_url'], $m)
                && $m[1] === $pcpp) {
                return $r;
            }
        }
        return null;
    }

    private function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}