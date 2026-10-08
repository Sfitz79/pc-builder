<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Component;
use App\Models\Manufacturer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Imports the PCPartPicker UK catalogue scraped by the legacy React app
 * (Sfitz79/pc-builder) into the Laravel components table so the builder and
 * the SEO guides reflect current UK market pricing.
 *
 * The scraped JSON files live in database/scraped/*.json. A curated, modern
 * subset is imported (DDR5 memory, current-gen CPUs/GPUs, mainstream
 * motherboards) with sensible per-category caps so the catalogue stays
 * fast and relevant. Re-running the seeder refreshes prices via upsert.
 */
class ScrapedCatalogSeeder extends Seeder
{
    protected array $categoryOrder = [
        'cpu' => 1,
        'cooler' => 2,
        'motherboard' => 3,
        'gpu' => 4,
        'ram' => 5,
        'storage' => 6,
        'psu' => 7,
        'case' => 8,
    ];

    protected array $shares = [
        'cpu' => 0.30,
        'gpu' => 0.40,
        'ram' => 0.08,
        'storage' => 0.07,
        'motherboard' => 0.07,
        'psu' => 0.04,
        'case' => 0.02,
        'cooler' => 0.02,
    ];

    public function run(): void
    {
        foreach ($this->sources() as $slug => $file) {
            $path = $this->resolvePath($file);

            if ($path === null) {
                $this->command?->warn("ScrapedCatalogSeeder: {$file} not found, skipping.");

                continue;
            }

            $items = json_decode((string) file_get_contents($path), true);

            if (! is_array($items)) {
                continue;
            }

            $category = $this->category($slug);
            $count = 0;

            foreach ($this->filter($slug, $items) as $item) {
                $name = trim((string) ($item['productName'] ?? ''));
                $price = (float) ($item['price'] ?? 0);

                if ($name === '' || $price <= 0) {
                    continue;
                }

                $key = Str::slug($name) . '-' . substr(md5((string) ($item['url'] ?? $name)), 0, 6);

                Component::updateOrCreate(
                    ['slug' => $key],
                    $this->payload($category, $name, $price, $item)
                );

                $count++;
            }

            $this->command?->info("Imported {$count} {$slug} components from {$file}.");
        }

        $this->command?->info('ScrapedCatalogSeeder complete.');
    }

    /**
     * Fast bulk variant used when the catalogue must be imported inside a
     * single web request (e.g. a fresh deployment where build scripts do not
     * run). Categories and manufacturers are resolved once and the components
     * are upserted in a handful of statements keyed by the unique slug.
     */
    public function runFast(): void
    {
        $rows = [];
        $now = now();

        foreach ($this->sources() as $slug => $file) {
            $path = $this->resolvePath($file);

            if ($path === null) {
                continue;
            }

            $items = json_decode((string) file_get_contents($path), true);

            if (! is_array($items)) {
                continue;
            }

            $category = $this->category($slug);

            foreach ($this->filter($slug, $items) as $item) {
                $name = trim((string) ($item['productName'] ?? ''));
                $price = (float) ($item['price'] ?? 0);

                if ($name === '' || $price <= 0) {
                    continue;
                }

                $key = Str::slug($name) . '-' . substr(md5((string) ($item['url'] ?? $name)), 0, 6);

                $payload = $this->payload($category, $name, $price, $item);
                $payload['specs'] = is_array($payload['specs'])
                    ? json_encode($payload['specs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $payload['specs'];

                $rows[$key] = [
                    ...$payload,
                    'slug' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            Component::upsert(
                $chunk,
                ['slug'],
                // The identity columns are listed here as well as in payload().
                // upsert() silently DROPS any column absent from this list, so
                // omitting them would make the whole backfill a no-op that still
                // looked like it had worked - the same failure shape as the
                // fields this change was written to recover.
                [
                    'name', 'sku', 'description', 'price', 'currency', 'socket', 'wattage',
                    'stock', 'active', 'specs', 'source_url', 'manufacturer_id', 'updated_at',
                    'memory_speed', 'memory_type', 'cas_latency', 'module_config',
                    'interface', 'storage_type', 'mpn',
                    'case_type', 'side_panel', 'included_fans', 'radiator_size',
                ]
            );
        }
    }

    protected function sources(): array
    {
        return [
            'cpu' => 'cpu.json',
            'cooler' => 'cooler.json',
            'motherboard' => 'motherboard.json',
            'gpu' => 'gpu.json',
            'ram' => 'ram.json',
            'storage' => 'storage.json',
            'psu' => 'power-supply.json',
            'case' => 'case.json',
        ];
    }

    protected function filter(string $slug, array $items): array
    {
        $kept = [];

        foreach ($items as $item) {
            $name = strtolower((string) ($item['productName'] ?? ''));
            $specs = $item['specs'] ?? [];

            switch ($slug) {
                case 'cpu':
                    if (! preg_match('/ryzen\s?[3579]\s?[1-9]\d{2,3}|core i[3579]-[1-4]\d{3}x?|core ultra|pentium|athlon|core i[3579]-1[0-3]\d{2}/i', $name)) {
                        continue 2;
                    }
                    break;

                case 'gpu':
                    $chipset = strtolower((string) ($specs['chipset'] ?? ''));
                    if (! preg_match('/rtx\s?[2-5][0-9]|gtx\s?1[0-9]{3}|rx\s?[5-9][0-9]{2}|rx\s?[1-5]\d{3}|arc\s?[ab]/i', $chipset) && ! preg_match('/geforce|radeon|arc/i', $name)) {
                        continue 2;
                    }
                    break;

                case 'ram':
                    if (! isset($specs['speed'])) {
                        continue 2;
                    }
                    break;

                case 'motherboard':
                    $socket = strtoupper((string) ($specs['socketCPU'] ?? ''));
                    if (! in_array($socket, ['AM4', 'AM5', 'LGA1200', 'LGA1700', 'LGA1851'], true)) {
                        continue 2;
                    }
                    break;

                case 'psu':
                    $psuWatt = (string) ($specs['wattage'] ?? '');
                    if (! preg_match('/\b([3-9]\d\d|1\d\d\d)\s*W\b/i', $psuWatt)) {
                        continue 2;
                    }
                    break;

                case 'storage':
                    $storageType = strtolower((string) ($specs['type'] ?? ''));
                    if (! str_contains($storageType, 'ssd') && ! str_contains($storageType, 'nvme') && ! str_contains($storageType, 'hdd')) {
                        continue 2;
                    }
                    break;
            }

            $kept[] = $item;

            $cap = $this->cap($slug);

            if (count($kept) >= $cap) {
                break;
            }
        }

        return $kept;
    }

    protected function cap(string $slug): int
    {
        return match ($slug) {
            'cpu' => 500,
            'gpu' => 500,
            'ram' => 500,
            'motherboard' => 500,
            'psu' => 500,
            'storage' => 500,
            'case' => 500,
            'cooler' => 500,
            default => 200,
        };
    }

    protected function resolvePath(string $file): ?string
    {
        $path = base_path('database/scraped/' . $file);

        return is_file($path) ? $path : null;
    }

    protected function category(string $slug): Category
    {
        return Category::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucwords(str_replace('-', ' ', $slug)),
                'sort_order' => $this->categoryOrder[$slug] ?? 99,
                'active' => true,
            ]
        );
    }

    protected array $manufacturerCache = [];

    protected function manufacturer(string $name): int
    {
        $brand = preg_split('/[\s]+/', trim($name))[0] ?? 'Generic';
        $brand = trim((string) $brand, "()[]-,");
        $slug = Str::slug($brand);

        if (isset($this->manufacturerCache[$slug])) {
            return $this->manufacturerCache[$slug];
        }

        $id = Manufacturer::firstOrCreate(
            ['slug' => $slug],
            ['name' => $brand, 'active' => true]
        )->id;

        return $this->manufacturerCache[$slug] = $id;
    }

    protected function payload(Category $category, string $name, float $price, array $item): array
    {
        $raw = $item['specs'] ?? [];
        $available = (bool) ($item['availability'] ?? true);

        $socket = null;
        $wattage = null;
        $specs = [];
        // New first-class identity columns; see migration 2026_10_07_000001.
        $identity = [];

        // The source URL carries the manufacturer part number, which is the
        // only true product identity we have:
        //   .../crucial-pro-32-gb-2-x-16-gb-ddr5-6400-cl38-memory-cp2k16g64c38u5b
        //                                                              ^^^^^ MPN
        $mpn = $this->mpnFromUrl((string) ($item['url'] ?? ''));
        if ($mpn !== null) {
            $identity['mpn'] = $mpn;
        }

        if ($category->slug === 'cpu') {
            $cores = $raw['coreCount'] ?? null;

            if ($cores !== null && is_numeric($cores)) {
                $specs['cores'] = (int) $cores;
                $specs['threads'] = (int) $cores * 2;
            }

            if (! empty($raw['tdp'])) {
                $wattage = (int) filter_var($raw['tdp'], FILTER_SANITIZE_NUMBER_INT);
            }

            $socket = $this->cpuSocket($name);
        }

        if ($category->slug === 'gpu') {
            $memory = (string) ($raw['memory'] ?? '');
            if ($memory !== '') {
                $specs['memory'] = str_replace(' ', '', $memory);
            }
            $specs['chipset'] = $raw['chipset'] ?? null;
            $specs['length'] = $raw['length'] ?? null;
        }

        if ($category->slug === 'ram') {
            $specs['speed'] = $raw['speed'] ?? null;
            $specs['capacity'] = $this->ramCapacity($name, $raw);

            // Identity fields the scraper ALREADY fetched and this seeder used
            // to throw away. Without them two different 32GB kits were
            // indistinguishable - see migration 2026_10_07_000001.
            $speed = trim((string) ($raw['speed'] ?? ''));
            if ($speed !== '') {
                $identity['memory_speed'] = $speed;
                if (preg_match('/DDR\s?([345])/i', $speed, $m)) {
                    $identity['memory_type'] = 'DDR' . $m[1];
                }
            }
            // CL is stored as a NUMBER because it is compared numerically
            // ("30" sorts worse than "28"); the scraper gives us "30" here and
            // the pre-first-word figure separately, which we do not need.
            $cas = $this->toNumber($raw['cASLatency'] ?? null);
            if ($cas !== null) {
                $identity['cas_latency'] = $cas;
            }
            $modules = trim((string) ($raw['modules'] ?? ''));
            if ($modules !== '') {
                $identity['module_config'] = $modules;
            }
        }

        if ($category->slug === 'storage') {
            $specs['capacity'] = $this->storageCapacity($name, $raw);
            $interface = trim((string) ($raw['interface'] ?? ''));
            if ($interface !== '') {
                $identity['interface'] = $interface;
            }
            $type = trim((string) ($raw['type'] ?? ''));
            if ($type !== '') {
                $identity['storage_type'] = $type;
                $specs['type'] = $type;
            }
        }

        if ($category->slug === 'case') {
            // Persisted for the first time. The scrape has always carried these
            // three; 197 case rows share a name and had NO discriminator at all
            // because none of it was being written.
            $type = trim((string) ($raw['type'] ?? ''));
            if ($type !== '') {
                $identity['case_type'] = $type;
            }
            $panel = trim((string) ($raw['sidePanel'] ?? ''));
            if ($panel !== '') {
                $identity['side_panel'] = $panel;
            }
            $fans = $this->toNumber($raw['includedFans'] ?? null);
            if ($fans !== null) {
                $identity['included_fans'] = $fans;
            }
        }

        if ($category->slug === 'cooler') {
            $radiator = trim((string) ($raw['radiatorSize'] ?? ''));
            if ($radiator !== '') {
                $identity['radiator_size'] = $radiator;
            }
        }

        if ($category->slug === 'motherboard') {
            $socket = $raw['socketCPU'] ?? null;
        }

        if ($category->slug === 'psu') {
            preg_match('/\b([5-9]\d\d|1\d\d\d)\s*W\b/i', (string) ($raw['wattage'] ?? ''), $m);
            $wattage = isset($m[1]) ? (int) $m[1] : null;

            /*
             * THE SCRAPE HAS ALWAYS CARRIED THE EFFICIENCY RATING. WE WERE THROWING IT AWAY.
             *
             * Measured 2026-10-08 over database/scraped/power-supply.json: 3,669 PSU
             * rows, of which 3,245 (88.44%) carry specs.efficiencyRating, and 496 of
             * the 530 seedable rows (93.58%) do. Real values, six clean tiers, no
             * sentinels: 80+ Gold, 80+ Bronze, 80+ Platinum, 80+ Titanium, 80+ Silver,
             * and a bare '80+' meaning certified-but-tier-not-stated.
             *
             * This branch assigned only $wattage and nothing else, so $specs stayed
             * empty and line 407's array_filter emptied it again - which is why 287 of
             * 290 PSU rows had specs IS NULL and why the policy had to infer efficiency
             * from the product NAME. The data was in the file the whole time.
             *
             * IT ALSO DISCARDED specs.modular and specs.type, both present on all
             * 3,669 rows. All three are recovered here in one edit.
             *
             * WARNING - DO NOT BACKFILL FROM THE WRONG KEY. Every PSU row also carries a
             * top-level `rating` field, which is the RETAILER CUSTOMER REVIEW SCORE
             * (paired with ratingCount, 0-976 reviews), not efficiency. Reading it
             * would stamp every PSU with a fake efficiency rating of 4-5. The correct
             * key is the nested specs.efficiencyRating below.
             *
             * A bare '80+' is stored as-is. It is a genuine 80 PLUS certification with
             * no tier stated, and consumers must say exactly that rather than guessing
             * Gold or quietly folding it into Bronze.
             */
            $efficiency = trim((string) ($raw['efficiencyRating'] ?? ''));
            if ($efficiency !== '') {
                $specs['efficiency_rating'] = $efficiency;
            }
            $modular = trim((string) ($raw['modular'] ?? ''));
            if ($modular !== '') {
                $specs['modular'] = $modular;
            }
            $formFactor = trim((string) ($raw['type'] ?? ''));
            if ($formFactor !== '') {
                $specs['form_factor'] = $formFactor;
            }
        }

        $specs = array_filter($specs, fn ($value) => $value !== null && $value !== '');

        return [
            'category_id' => $category->id,
            'manufacturer_id' => $this->manufacturer($name),
            'name' => $name,
            'sku' => 'PCPP-' . strtoupper(substr(sha1((string) ($item['url'] ?? $name)), 0, 12)),
            'description' => 'Sourced from UK retailers via PCPartPicker.',
            'price' => $price,
            'currency' => 'GBP',
            'socket' => $socket,
            'wattage' => $wattage,
            'stock' => $available ? 1 : 0,
            'active' => true,
            'specs' => $specs !== [] ? $specs : null,
            'source_url' => (string) ($item['url'] ?? null) ?: null,
            // Product photography, exported by scripts/export-catalogue-to-seed.php.
            //
            // Without this the deployed site has no product images at all:
            // displayImage() falls back to the per-category placeholder, so
            // production would show generic category art for every component
            // even though 2,446 seed records now carry a real image URL.
            'image_url' => trim((string) ($item['imageUrl'] ?? '')) ?: null,
            // Stamped so the price-freshness gate starts its 7-day window at
            // deploy time rather than at some unknown earlier scrape.
            'price_checked_at' => now(),
        ] + $identity;
    }

    /**
     * Parse a value that may be "38", 38 or "38 CL" into an int.
     *
     * The scraper is inconsistent - some fields arrive as strings, some as
     * numbers, some with units - so every numeric identity field goes through
     * here rather than being cast blindly.
     */
    protected function toNumber($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $digits = preg_replace('/[^0-9.]/', '', (string) $value);

        return ($digits !== '' && is_numeric($digits)) ? (int) round((float) $digits) : null;
    }

    /**
     * Manufacturer part number from the source product URL.
     *
     * The part code is the LAST HYPHEN-DELIMITED TOKEN of the URL's final
     * segment, not the segment itself:
     *
     *   .../v-color-manta-xsky-rgb-32-gb-...-cl30-memory-tmxsal1660830kwk
     *                                                                  ^^^^^^^^^^^^
     *   .../corsair-vengeance-64-gb-...-cl30-memory-cmk64gx5m2b6000z30
     *
     * Taking the whole segment (the full descriptive slug) never matches and
     * silently yields NULL - which is exactly what happened the first time.
     *
     * This matters for identity, not tidiness. Trailing codes distinguish
     * products that share a marketing name AND near-identical specs:
     * cmk64gx5m2b6000z30 is black, cmk64gx5m2b6000c30 is white. Treating such
     * a pair as "the same product listed twice" and removing one would delete a
     * real colour variant from a live catalogue.
     */
    protected function mpnFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $last = end($segments);

        if (! is_string($last) || $last === '') {
            return null;
        }

        $tokens = array_values(array_filter(explode('-', $last), fn ($t) => $t !== ''));
        $code = (string) end($tokens);

        // Must look like a code: alphanumeric, contains a digit, 5-24 chars.
        // This rejects prose tokens such as "memory", "black" or "warranty",
        // which would otherwise be written out as a bogus part number.
        if (! preg_match('/^(?=.*\d)[a-z0-9]{5,24}$/i', $code)) {
            return null;
        }

        return strtoupper($code);
    }

    protected function cpuSocket(string $name): ?string
    {
        $upper = strtoupper($name);

        return match (true) {
            str_contains($upper, 'THREADRIPPER') => 'sTR5',
            str_contains($upper, 'RYZEN') => 'AM5',
            str_contains($upper, 'CORE ULTRA') => 'LGA1851',
            preg_match('/CORE I[5-9]-1(?:[234])\d{2}/', $upper) === 1 => 'LGA1700',
            default => null,
        };
    }

    protected function ramCapacity(string $name, array $raw): ?string
    {
        $modules = (string) ($raw['modules'] ?? '');

        if (preg_match('/(\d+)\s*[xX]\s*(\d+)GB/', $modules, $m)) {
            return ((int) $m[1] * (int) $m[2]) . 'GB';
        }

        if (preg_match('/(\d+)\s*GB/', $name, $m)) {
            return $m[1] . 'GB';
        }

        return null;
    }

    protected function storageCapacity(string $name, array $raw): ?string
    {
        $specs = (string) ($raw['capacity'] ?? '');

        if (preg_match('/(\d+)\s*(TB|GB)/i', $specs, $m)) {
            return $m[2] === 'TB' ? ((int) $m[1] * 1024) . 'GB' : $m[1] . 'GB';
        }

        if (preg_match('/(\d+)\s*(TB|GB)/i', $name, $m)) {
            return $m[2] === 'TB' ? ((int) $m[1] * 1024) . 'GB' : $m[1] . 'GB';
        }

        return null;
    }
}
