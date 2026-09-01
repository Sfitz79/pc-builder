<?php

/*
|--------------------------------------------------------------------------
| Current-market catalogue import (build-time)
|--------------------------------------------------------------------------
|
| Runs during `composer install` (post-autoload-dump) so every deployment
| refreshes component prices from the scraped PCPartPicker UK catalogue.
|
| Deliberately fail-safe: any error (missing DB, unreachable Neon, etc.) is
| swallowed and the build continues. The import itself is an idempotent
| upsert keyed by slug, so it is safe to run repeatedly.
|
*/

require __DIR__ . '/../vendor/autoload.php';

try {
    $app = require __DIR__ . '/../bootstrap/app.php';

    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $app->make(Illuminate\Database\DatabaseManager::class)->connection();

    (new Database\Seeders\ScrapedCatalogSeeder())->run();

    fwrite(STDOUT, "scrape-catalog: import complete\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'scrape-catalog: skipped (' . $e->getMessage() . ")\n");
}

exit(0);
