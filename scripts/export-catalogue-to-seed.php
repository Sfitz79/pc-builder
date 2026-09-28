<?php

/*
|--------------------------------------------------------------------------
| Export the live catalogue back into the build-time seed files
|--------------------------------------------------------------------------
|
| Why this exists (2026-09-28):
|
| Production (pctechguy.app on Vercel) does NOT ship the SQLite catalogue -
| database/*.sqlite is gitignored. Every fresh deploy builds an empty database
| and repopulates it from database/scraped/*.json via
| ScrapedCatalogSeeder, which composer runs from post-autoload-dump.
|
| The 2026-09-28 live refresh (components:refresh-catalogue) wrote 2,694 rows
| to the LOCAL database only. Nothing had been written back to the seed JSON,
| so deploying at that moment would have silently rolled every price back to
| the 2026-07-31 scrape - discarding 1,159 raises and 595 reductions. That is
| the single most dangerous thing about this workflow: the database is the
| working copy, and the JSON is what production actually reads.
|
| This script walks the seed JSON and, for every record that corresponds to a
| real catalogue component (same key the seeder computes), copies across the
| live price, stock availability and image URL. Only matched records are
| touched: the seeder filters and caps the JSON before seeding, so unmatched
| records are never imported and rewriting them would be noise.
|
| Usage:  php scripts/export-catalogue-to-seed.php [--dry-run]
|
*/

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use Illuminate\Support\Str;

$dryRun = in_array('--dry-run', $argv, true);

$files = [
    'cpu' => 'cpu.json',
    'cooler' => 'cooler.json',
    'motherboard' => 'motherboard.json',
    'gpu' => 'gpu.json',
    'ram' => 'ram.json',
    'storage' => 'storage.json',
    'psu' => 'power-supply.json',
    'case' => 'case.json',
];

// Only components that are actually publishable should carry an exportable
// price. Quarantined and unverified rows are kept OUT of the seed data so a
// deploy cannot resurrect a part we have already refused to stand behind.
    $components = Component::where('active', true)
    ->whereNotNull('source_url')
    ->where('price_checked_at', '!=', null)
    ->get()
    ->keyBy('slug');

printf(
    "Exporting %d verified components into %d seed files%s\n\n",
    $components->count(),
    count($files),
    $dryRun ? '  (DRY RUN - nothing written)' : ''
);

$grandPrice = 0;
$grandImage = 0;
$grandStock = 0;
$grandRecords = 0;

foreach ($files as $slug => $file) {
    $path = $root . '/database/scraped/' . $file;
    $items = json_decode((string) file_get_contents($path), true);

    if (! is_array($items)) {
        fwrite(STDERR, "  {$file}: unreadable, skipped\n");

        continue;
    }

    $matched = 0;
    $priceChanged = 0;
    $imageAdded = 0;
    $stockChanged = 0;

    foreach ($items as $i => $item) {
        $name = trim((string) ($item['productName'] ?? ''));

        if ($name === '') {
            continue;
        }

        $url = (string) ($item['url'] ?? $name);
        $key = Str::slug($name) . '-' . substr(md5($url), 0, 6);
        $component = $components->get($key);

        if ($component === null) {
            continue;
        }

        $matched++;

        $newPrice = round((float) $component->price, 2);

        if (abs($newPrice - (float) ($item['price'] ?? 0)) > 0.005) {
            $items[$i]['price'] = $newPrice;
            $priceChanged++;
        }

        // Scraper field consumed by the seeder: availability drives `stock`.
        $available = (int) $component->stock > 0;

        if (! array_key_exists('availability', $item) || (bool) $item['availability'] !== $available) {
            $items[$i]['availability'] = $available;
            $stockChanged++;
        }

        // Consumed by the seeder only once ScrapedCatalogSeeder was taught to
        // read it - without this, production has no product photography at all
        // and falls back to category placeholder images.
        $image = trim((string) ($component->image_url ?? ''));

        if ($image !== '' && ($item['imageUrl'] ?? null) !== $image) {
            $items[$i]['imageUrl'] = $image;
            $imageAdded++;
        }
    }

    if (! $dryRun) {
        file_put_contents(
            $path,
            json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    $grandPrice += $priceChanged;
    $grandImage += $imageAdded;
    $grandStock += $stockChanged;
    $grandRecords += $matched;

    printf(
        "  %-12s %-20s matched %4d | price %4d | stock %3d | image %4d\n",
        $slug,
        $file,
        $matched,
        $priceChanged,
        $stockChanged,
        $imageAdded
    );
}

printf(
    "\n%s  matched %d records | %d price corrections | %d stock updates | %d image URLs\n",
    $dryRun ? 'WOULD WRITE:' : 'WROTE:',
    $grandRecords,
    $grandPrice,
    $grandStock,
    $grandImage
);

if (! $dryRun) {
    echo "\nRemember: database/*.sqlite is gitignored, so database/scraped/*.json\n"
        ."IS the catalogue. Any live refresh must be exported back through this\n"
        ."script or a deploy will silently roll every price back.\n";
}
