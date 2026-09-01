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
                ['name', 'sku', 'description', 'price', 'currency', 'socket', 'wattage', 'stock', 'active', 'specs', 'manufacturer_id', 'updated_at']
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
                    if (! preg_match('/ryzen\s?[579]\s?[789]\d{2}|core i[5-9]-1\d|core i[5-9]-2\d|core ultra/', $name)) {
                        continue 2;
                    }
                    break;

                case 'gpu':
                    $chipset = strtolower((string) ($specs['chipset'] ?? ''));
                    if (! preg_match('/rtx\s?4[0-9]|rtx\s?5[0-9]|rx\s?[679][0-9]|arc\s?[ab]/', $chipset)) {
                        continue 2;
                    }
                    break;

                case 'ram':
                    if (! isset($specs['speed']) || ! str_contains(strtoupper($specs['speed']), 'DDR5')) {
                        continue 2;
                    }
                    break;

                case 'motherboard':
                    $socket = strtoupper((string) ($specs['socketCPU'] ?? ''));
                    if (! in_array($socket, ['AM4', 'AM5', 'LGA1700', 'LGA1851'], true)) {
                        continue 2;
                    }
                    break;

                case 'psu':
                    $psuWatt = (string) ($specs['wattage'] ?? '');
                    if (! preg_match('/\b([5-9]\d\d|1\d\d\d)\s*W\b/i', $psuWatt)) {
                        continue 2;
                    }
                    break;

                case 'storage':
                    $storageType = strtolower((string) ($specs['type'] ?? ''));
                    if (! str_contains($storageType, 'ssd')) {
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
            'cpu' => 250,
            'gpu' => 250,
            'ram' => 150,
            'motherboard' => 150,
            'psu' => 150,
            'storage' => 120,
            'case' => 120,
            'cooler' => 120,
            default => 50,
        };
    }

    protected function resolvePath(string $file): ?string
    {
        $candidates = [
            base_path('database/scraped/' . $file),
            'C:/Users/simon/WebstormProjects/pc-builder/scraped_data/' . $file,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
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
        }

        if ($category->slug === 'ram') {
            $specs['speed'] = $raw['speed'] ?? null;
            $specs['capacity'] = $this->ramCapacity($name, $raw);
        }

        if ($category->slug === 'storage') {
            $specs['capacity'] = $this->storageCapacity($name, $raw);
        }

        if ($category->slug === 'motherboard') {
            $socket = $raw['socketCPU'] ?? null;
        }

        if ($category->slug === 'psu') {
            preg_match('/\b([5-9]\d\d|1\d\d\d)\s*W\b/i', (string) ($raw['wattage'] ?? ''), $m);
            $wattage = isset($m[1]) ? (int) $m[1] : null;
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
        ];
    }

    protected function cpuSocket(string $name): ?string
    {
        $upper = strtoupper($name);

        return match (true) {
            str_contains($upper, 'THREADRIPPER') => 'sTR5',
            str_contains($upper, 'RYZEN') => 'AM5',
            str_contains($upper, 'CORE ULTRA') => 'LGA1851',
            preg_match('/CORE I(12|13|14)-/', $upper) === 1 => 'LGA1700',
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
